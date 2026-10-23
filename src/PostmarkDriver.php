<?php

namespace Acelle\Plugin\Postmark;

use Acelle\Plugin\Postmark\Postmark\Client;
use Acelle\Plugin\Postmark\Postmark\Error;
use App\Model\BounceLog;
use App\SendingServers\Capabilities\ProvidesConnectionFieldsView;
use App\SendingServers\Capabilities\ReceivesWebhooks;
use App\SendingServers\Capabilities\SupportsIdentitySync;
use App\SendingServers\Capabilities\SupportsRemoteDomainVerify;
use App\SendingServers\DomainVerification\CheckStatus;
use App\SendingServers\DomainVerification\DnsRecord;
use App\SendingServers\DomainVerification\RecordPurpose;
use App\SendingServers\DomainVerification\VerificationResult;
use App\SendingServers\Drivers\AbstractDriver;
use App\SendingServers\Drivers\SendResult;
use App\SendingServers\Drivers\TestResult;
use App\SendingServers\Exceptions\DomainNotAtVendor;
use App\SendingServers\Identities\Identity;
use App\SendingServers\Identities\IdentityKind;
use App\SendingServers\Identities\IdentityStatus;
use App\SendingServers\Webhooks\BounceReceived;
use App\SendingServers\Webhooks\BounceType;
use App\SendingServers\Webhooks\ComplaintReceived;
use App\SendingServers\Webhooks\FeedbackType;
use App\SendingServers\Webhooks\IgnorableWebhookEvent;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Postmark driver — talks to Postmark (https://postmarkapp.com) via REST API.
 *
 * Capabilities: SendingDriver, ProvidesConnectionFieldsView, ReceivesWebhooks,
 *               SupportsIdentitySync, SupportsRemoteDomainVerify.
 *
 * Two-token model:
 *   postmark_server_token   — REQUIRED. Per-server. Used for /email and per-server
 *                             /webhooks (auto-register, list, delete).
 *   postmark_account_token  — OPTIONAL. Account-wide. Required ONLY for
 *                             identity sync (fetchIdentities, registerDomain,
 *                             pollDomain) which hit /domains and /senders.
 *                             If empty, those methods throw an explicit Error
 *                             telling admin to add the token.
 *
 *   postmark_message_stream — default "outbound" if blank. Override to send
 *                             on a different stream (e.g., "broadcast").
 *
 *   postmark_webhook_id     — auto-populated. Postmark's webhook id.
 *   postmark_webhook_user   — Basic auth username for webhook verification
 *                             (Postmark sends `Authorization: Basic …`
 *                             matching what we set in `HttpAuth` at register).
 *   postmark_webhook_pass   — companion password.
 *
 * Wire format (per postmarkapp.com/developer):
 *   Send:    POST /email
 *              X-Postmark-Server-Token: <server token>
 *              {From, To, Cc, Bcc, ReplyTo, Subject, HtmlBody, TextBody,
 *               Headers[], Attachments[], MessageStream}
 *              → 200 {To, SubmittedAt, MessageID:"uuid", ErrorCode:0, Message:"OK"}
 *   Webhook register: POST /webhooks  (server token)
 *              {Url, MessageStream, HttpAuth:{Username,Password},
 *               Triggers: {Bounce:{Enabled:true,IncludeContent:false},
 *                          SpamComplaint:{Enabled:true,IncludeContent:false},
 *                          Delivery:{Enabled:false}}}
 *              → 200 {ID, Url, ..., Triggers: {...}}
 *   Webhook delete: DELETE /webhooks/{id}  (server token)
 *   Webhook payload (Bounce):
 *              {RecordType:"Bounce", MessageID, Type, TypeCode, Email,
 *               From, BouncedAt, Description, Details, Inactive, ...}
 *   Webhook payload (SpamComplaint):
 *              {RecordType:"SpamComplaint", MessageID, Type:"SpamComplaint",
 *               TypeCode:512, Email, ...}
 *   Webhook payload (Delivery):
 *              {RecordType:"Delivery", MessageID, Recipient, ...}
 *   Domains: POST /domains      (account token) {Name}
 *            GET  /domains
 *            GET  /domains/{id}
 *            PUT  /domains/{id}/verifyDkim
 *            DELETE /domains/{id}
 *   Senders: GET /senders   (account token) — verified-sender list, for EMAIL identity
 *
 * runtime_message_id correlation:
 *   Send response's `MessageID` (UUID like 0a129aee-…) matches webhook's
 *   `MessageID` exactly — no munging required.
 *
 * Webhook auth model:
 *   Postmark does HTTP Basic auth at register time (HttpAuth.Username/Password)
 *   and echoes back as `Authorization: Basic base64(user:pass)` on every POST.
 *   No HMAC option.
 */
class PostmarkDriver extends AbstractDriver implements
    ProvidesConnectionFieldsView,
    ReceivesWebhooks,
    SupportsIdentitySync,
    SupportsRemoteDomainVerify
{
    public const TYPE = 'acelle-postmark';

    private ?Client $client = null;

    public function getServiceName(): string  { return 'Postmark'; }
    public function getServiceIcon(): string  { return 'mark_email_read'; }
    public function getServiceColor(): string { return 'var(--chart-4)'; }

    public function connectionFieldsView(): string
    {
        return 'postmark::sending-servers._fields_connection';
    }

    public function validationRules(): array
    {
        return [
            'cols' => [
                'postmark_server_token'   => 'required|string|max:255',
                'postmark_account_token'  => 'nullable|string|max:255',
                'postmark_message_stream' => 'nullable|string|max:64',
            ],
            'settings' => [
                'name'               => 'required',
                'default_from_email' => 'email',
            ],
        ];
    }

    /**
     * POST /email — returns MessageID as UUID. Use that as runtime_message_id;
     * webhook payload contains it verbatim, no munging required.
     */
    public function send(\Symfony\Component\Mime\Email $message, array $params = []): SendResult
    {
        $payload = $this->buildPayload($message);
        $resp = $this->client()->serverRequest('POST', 'email', $payload);

        $messageId = $resp['MessageID'] ?? null;
        $errCode   = (int) ($resp['ErrorCode'] ?? 0);
        if ($errCode !== 0 || !is_string($messageId) || $messageId === '') {
            throw new \RuntimeException(
                'Postmark send failed (ErrorCode=' . $errCode . '): ' . ($resp['Message'] ?? json_encode($resp))
            );
        }
        return new SendResult(runtimeMessageId: $messageId, raw: $resp);
    }

    /**
     * Probe — `GET /server` requires server token and returns server settings.
     * 200 with valid token, 401/422 with bad.
     */
    public function test(): TestResult
    {
        try {
            $this->client()->serverRequest('GET', 'server', null);
            return TestResult::success();
        } catch (Error $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    /**
     * Idempotently register the Bounce + SpamComplaint webhook with Postmark.
     * Stores webhook id + the Basic-auth user/pass we registered.
     */
    public function setupBeforeSend(string $fromEmailAddress): void
    {
        if ($this->config('postmark_webhook_id')) {
            return;
        }

        $url = $this->webhookUrl();
        if (!$this->isPublicHttpsUrl($url)) {
            Log::info('Postmark webhook auto-register skipped — needs public HTTPS URL', [
                'server_id'   => $this->server->id,
                'webhook_url' => $url,
                'hint'        => 'Use a tunnel (ngrok/cloudflared) in dev, or rely on production deploy.',
            ]);
            return;
        }

        $authUser = 'acelle-' . bin2hex(random_bytes(4));
        $authPass = bin2hex(random_bytes(16));
        $stream   = (string) ($this->config('postmark_message_stream', '') ?: 'outbound');

        try {
            $resp = $this->client()->serverRequest('POST', 'webhooks', [
                'Url'           => $url,
                'MessageStream' => $stream,
                'HttpAuth'      => ['Username' => $authUser, 'Password' => $authPass],
                'Triggers' => [
                    'Bounce'             => ['Enabled' => true,  'IncludeContent' => false],
                    'SpamComplaint'      => ['Enabled' => true,  'IncludeContent' => false],
                    'Delivery'           => ['Enabled' => false],
                    'Open'               => ['Enabled' => false, 'PostFirstOpenOnly' => false],
                    'Click'              => ['Enabled' => false],
                    'SubscriptionChange' => ['Enabled' => false],
                ],
            ]);
        } catch (Error $e) {
            Log::warning('Postmark webhook auto-register failed', [
                'server_id' => $this->server->id,
                'error'     => $e->getMessage(),
            ]);
            return;
        }

        $id = $resp['ID'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            Log::warning('Postmark webhook register: response missing ID', [
                'server_id' => $this->server->id,
                'response'  => $resp,
            ]);
            return;
        }

        $this->server->setConfig('postmark_webhook_id',   (int) $id);
        $this->server->setConfig('postmark_webhook_user', $authUser);
        $this->server->setConfig('postmark_webhook_pass', $authPass);
        $this->server->save();
    }

    public function teardownWebhook(): void
    {
        $id = $this->config('postmark_webhook_id');
        if (!$id) {
            return;
        }
        $this->client()->serverRequest('DELETE', 'webhooks/' . (int) $id, null);
        $this->server->setConfig('postmark_webhook_id',   null);
        $this->server->setConfig('postmark_webhook_user', null);
        $this->server->setConfig('postmark_webhook_pass', null);
        $this->server->save();
    }

    public function webhookUrl(): string
    {
        return route('webhook.handle', [
            'driverType' => $this->server->type,
            'serverUid'  => $this->server->uid,
        ]);
    }

    public function verifyWebhook(Request $request): void
    {
        $expectedUser = (string) $this->config('postmark_webhook_user', '');
        $expectedPass = (string) $this->config('postmark_webhook_pass', '');
        if ($expectedUser === '' || $expectedPass === '') {
            Log::warning('Postmark webhook accepted without basic-auth verification', [
                'server_id' => $this->server->id,
                'reason'    => 'webhook user/pass not configured (setupBeforeSend never ran?)',
            ]);
            return;
        }

        $auth = (string) $request->header('Authorization', '');
        if (!preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
            throw new \RuntimeException('Postmark webhook missing Basic auth header');
        }
        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new \RuntimeException('Postmark webhook Basic auth header malformed');
        }
        [$user, $pass] = explode(':', $decoded, 2);

        if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
            throw new \RuntimeException('Postmark webhook auth mismatch');
        }
    }

    /**
     * Discriminator on Postmark webhooks is `RecordType` (NOT TypeCode —
     * SpamComplaint and SpamNotification share TypeCode 512 but differ in
     * RecordType).
     *
     * Engagement events (Delivery, Open, Click, SubscriptionChange) are
     * subscribed=false in setupBeforeSend, but enumerate here too so we
     * handle them gracefully if admin enables them later via Postmark UI.
     */
    private const IGNORABLE_RECORDS = [
        'Delivery', 'Open', 'Click', 'SubscriptionChange',
        'Inbound',  // separate inbound stream, not us
    ];

    public function parseWebhook(Request $request): iterable
    {
        $rawBody = $request->getContent();
        $json = json_decode($rawBody, true);

        if (!is_array($json)) {
            Log::warning('Postmark webhook: body is not JSON', [
                'server_id' => $this->server->id,
                'body'      => $rawBody,
            ]);
            return;
        }

        $recordType = $json['RecordType'] ?? null;
        $messageId  = (string) ($json['MessageID'] ?? '');

        if (!is_string($recordType)) {
            Log::warning('Postmark webhook: missing RecordType field', [
                'server_id' => $this->server->id,
                'keys'      => array_keys($json),
            ]);
            yield new IgnorableWebhookEvent(reason: 'postmark:no-recordtype', rawPayload: $json, server: $this->server);
            return;
        }

        if (in_array($recordType, self::IGNORABLE_RECORDS, true)) {
            yield new IgnorableWebhookEvent(
                reason: 'postmark:' . $recordType,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        if ($messageId === '') {
            Log::info('Postmark webhook: actionable event without MessageID', [
                'server_id' => $this->server->id,
                'recordtype' => $recordType,
            ]);
            yield new IgnorableWebhookEvent(
                reason: 'postmark:no-id:' . $recordType,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        if ($recordType === 'Bounce') {
            // TypeCode within Bounce discriminates hard vs soft:
            //   1=HardBounce, 4096=SoftBounce, 2=Transient, others → hard-equivalent.
            // SoftBounce → ignorable (engine retries). Hard variants blacklist.
            $typeCode = (int) ($json['TypeCode'] ?? 0);
            $type     = (string) ($json['Type'] ?? '');
            $isSoft   = $typeCode === 4096 || $type === 'SoftBounce' || $typeCode === 2 || $type === 'Transient';

            if ($isSoft) {
                yield new IgnorableWebhookEvent(
                    reason: 'postmark:soft_bounce:' . $type,
                    rawPayload: $json,
                    server: $this->server,
                );
                return;
            }

            yield new BounceReceived(
                runtimeMessageId: $messageId,
                type: BounceType::HARD,
                bounceTypeRaw: BounceLog::HARD,
                shouldBlacklist: true,
                rawForDb: $rawBody,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        if ($recordType === 'SpamComplaint') {
            yield new ComplaintReceived(
                runtimeMessageId: $messageId,
                type: FeedbackType::SPAM,
                feedbackTypeRaw: 'spam',
                shouldBlacklist: false,
                shouldMarkSpamReported: true,
                rawForDb: $rawBody,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        Log::info('Postmark webhook: unknown RecordType', [
            'server_id' => $this->server->id,
            'recordtype' => $recordType,
        ]);
        yield new IgnorableWebhookEvent(
            reason: 'postmark:unknown:' . $recordType,
            rawPayload: $json,
            server: $this->server,
        );
    }

    // ─── SupportsIdentitySync (requires account token) ─────────────────────

    public function fetchIdentities(): array
    {
        if ($this->config('postmark_account_token', '') === '') {
            Log::info('Postmark fetchIdentities skipped — postmark_account_token not configured', [
                'server_id' => $this->server->id,
            ]);
            return [];
        }

        $identities = [];

        // EMAIL — verified senders (account-wide).
        try {
            $resp = $this->client()->accountRequest('GET', 'senders?count=500&offset=0', null);
            foreach (($resp['SenderSignatures'] ?? []) as $s) {
                if (empty($s['EmailAddress'])) continue;
                $identities[] = Identity::email(
                    address: (string) $s['EmailAddress'],
                    status: !empty($s['Confirmed']) ? IdentityStatus::VERIFIED : IdentityStatus::PENDING,
                    displayName: $s['Name'] ?? null,
                    vendorMeta: [
                        'id'          => $s['ID']          ?? null,
                        'confirmed'   => $s['Confirmed']   ?? null,
                        'spfVerified' => $s['SPFVerified'] ?? null,
                    ],
                );
            }
        } catch (Error $e) {
            Log::warning('Postmark fetchIdentities: senders list failed', [
                'server_id' => $this->server->id,
                'error'     => $e->getMessage(),
            ]);
        }

        // DOMAIN — domains list (account-wide). List shape is reduced —
        // follow up per-entry GET for full DNS detail.
        try {
            $resp = $this->client()->accountRequest('GET', 'domains?count=500&offset=0', null);
            foreach (($resp['Domains'] ?? []) as $d) {
                $id = $d['ID'] ?? null;
                if ($id === null) continue;
                try {
                    $detail = $this->client()->accountRequest('GET', 'domains/' . $id, null);
                    $identities[] = $this->mapDomainToIdentity($detail);
                } catch (Error $e) {
                    Log::warning('Postmark fetchIdentities: domain detail failed', [
                        'server_id' => $this->server->id,
                        'domain'    => $d['Name'] ?? '?',
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        } catch (Error $e) {
            Log::warning('Postmark fetchIdentities: domains list failed', [
                'server_id' => $this->server->id,
                'error'     => $e->getMessage(),
            ]);
        }

        return $identities;
    }

    public function fetchIdentity(IdentityKind $kind, string $value): ?Identity
    {
        if ($this->config('postmark_account_token', '') === '') {
            return null;
        }

        if ($kind === IdentityKind::DOMAIN) {
            $id = $this->findDomainIdByName($value);
            if ($id === null) return null;
            try {
                $detail = $this->client()->accountRequest('GET', 'domains/' . $id, null);
            } catch (Error $e) {
                return null;
            }
            return $this->mapDomainToIdentity($detail);
        }

        if ($kind === IdentityKind::EMAIL) {
            try {
                $resp = $this->client()->accountRequest('GET', 'senders?count=500&offset=0', null);
            } catch (Error $e) {
                return null;
            }
            $needle = strtolower($value);
            foreach (($resp['SenderSignatures'] ?? []) as $s) {
                if (strtolower((string) ($s['EmailAddress'] ?? '')) === $needle) {
                    return Identity::email(
                        address: (string) $s['EmailAddress'],
                        status: !empty($s['Confirmed']) ? IdentityStatus::VERIFIED : IdentityStatus::PENDING,
                        displayName: $s['Name'] ?? null,
                    );
                }
            }
            return null;
        }

        return null;
    }

    // ─── SupportsRemoteDomainVerify (requires account token) ───────────────

    public function registerDomain(string $domain): VerificationResult
    {
        try {
            $resp = $this->client()->accountRequest('POST', 'domains', ['Name' => $domain]);
        } catch (Error $e) {
            // Already exists — find by name + GET detail.
            $id = $this->findDomainIdByName($domain);
            if ($id === null) throw $e;
            $resp = $this->client()->accountRequest('GET', 'domains/' . $id, null);
        }
        return $this->mapDomainToVerificationResult($resp);
    }

    public function pollDomain(string $domain): VerificationResult
    {
        $id = $this->findDomainIdByName($domain)
            ?? throw new DomainNotAtVendor('Postmark', $domain);

        try {
            $this->client()->accountRequest('PUT', 'domains/' . $id . '/verifyDkim', null);
        } catch (Error $e) {
            // Not fatal — proceed to GET to harvest current state.
            Log::info('Postmark pollDomain: verifyDkim returned non-2xx', [
                'server_id' => $this->server->id,
                'domain'    => $domain,
                'error'     => $e->getMessage(),
            ]);
        }

        try {
            $detail = $this->client()->accountRequest('GET', 'domains/' . $id, null);
        } catch (Error $e) {
            // Deleted between the list and this read. Anything else says
            // nothing about the domain.
            if ($e->statusCode === 404) {
                throw new DomainNotAtVendor('Postmark', $domain);
            }
            throw $e;
        }
        return $this->mapDomainToVerificationResult($detail);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * The domain's Postmark id; null when the account has no such domain. Walks
     * every page of the list (TotalCount), so null is a complete "no". A failed
     * request propagates: it says nothing about the domain — read as "no such
     * domain" it would archive one Postmark still has.
     */
    private function findDomainIdByName(string $name): ?int
    {
        $needle = strtolower($name);
        $offset = 0;
        do {
            $resp  = $this->client()->accountRequest('GET', 'domains?count=500&offset=' . $offset, null);
            $page  = $resp['Domains'] ?? null;
            $total = $resp['TotalCount'] ?? null;
            if (!is_array($page) || !is_int($total)) {
                throw new \UnexpectedValueException('Postmark answered the domain list without Domains / TotalCount: ' . json_encode($resp));
            }
            foreach ($page as $d) {
                if (strtolower((string) ($d['Name'] ?? '')) === $needle && isset($d['ID'])) {
                    return (int) $d['ID'];
                }
            }
            $offset += count($page);
        } while ($page !== [] && $offset < $total);

        return null;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) return false;
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost') return false;
        if (str_starts_with($host, '127.'))    return false;
        if (str_starts_with($host, '10.'))     return false;
        if (str_starts_with($host, '192.168.')) return false;
        if (str_ends_with($host, '.local'))    return false;
        if (str_ends_with($host, '.invalid'))  return false;
        return true;
    }

    /**
     * @param  array<string, mixed>  $domain   detail blob from GET /domains/{id}
     */
    private function mapDomainToIdentity(array $domain): Identity
    {
        $dkim = !empty($domain['DKIMVerified']);
        $rp   = !empty($domain['ReturnPathDomainVerified']);

        return Identity::domain(
            name: (string) ($domain['Name'] ?? ''),
            status: $this->domainStatusOf($domain),
            dkimStatus: $dkim ? IdentityStatus::VERIFIED : IdentityStatus::PENDING,
            vendorMeta: [
                'id'                       => $domain['ID']                       ?? null,
                'DKIMVerified'             => $dkim,
                'ReturnPathDomainVerified' => $rp,
                'WeakDKIM'                 => $domain['WeakDKIM'] ?? null,
                'DKIMUpdateStatus'         => $domain['DKIMUpdateStatus'] ?? null,
            ],
        );
    }

    /**
     * A domain's status, the same for identity sync and Verify: VERIFIED once
     * Postmark has verified both its DKIM and its Return-Path. Postmark has no
     * rejected or switched-off domain state, so anything short of that is
     * PENDING: the DNS is not right yet.
     *
     * @param  array<string, mixed>  $domain   detail blob from GET /domains/{id}
     */
    private function domainStatusOf(array $domain): IdentityStatus
    {
        return !empty($domain['DKIMVerified']) && !empty($domain['ReturnPathDomainVerified'])
            ? IdentityStatus::VERIFIED
            : IdentityStatus::PENDING;
    }

    /**
     * @param  array<string, mixed>  $domain
     */
    private function mapDomainToVerificationResult(array $domain): VerificationResult
    {
        return new VerificationResult(
            domain:   (string) ($domain['Name'] ?? ''),
            records:  $this->extractDnsRecords($domain),
            status:   $this->domainStatusOf($domain),
            polledAt: new DateTimeImmutable(),
            vendorMeta: [
                'id'                       => $domain['ID']                       ?? null,
                'DKIMVerified'             => $domain['DKIMVerified']             ?? null,
                'ReturnPathDomainVerified' => $domain['ReturnPathDomainVerified'] ?? null,
            ],
        );
    }

    /**
     * Postmark exposes DKIM + Return-Path records as named top-level fields:
     *   DKIMHost / DKIMTextValue   — TXT
     *   DKIMPendingHost / DKIMPendingTextValue (during rotation; nullable)
     *   ReturnPathDomain / ReturnPathDomainCNAMEValue   — CNAME
     * No SPF/DMARC record from Postmark — admin uses their existing SPF
     * with `include:spf.mtasv.net` per Postmark docs.
     *
     * @param  array<string, mixed>  $domain
     * @return list<DnsRecord>
     */
    private function extractDnsRecords(array $domain): array
    {
        $out = [];
        $dkimVerified = !empty($domain['DKIMVerified']);
        $rpVerified   = !empty($domain['ReturnPathDomainVerified']);

        if (!empty($domain['DKIMHost']) && !empty($domain['DKIMTextValue'])) {
            $name = (string) $domain['DKIMHost'];
            $out[] = new DnsRecord(
                purpose:  RecordPurpose::DKIM,
                type:     'TXT',
                name:     $name,
                value:    (string) $domain['DKIMTextValue'],
                status:   $dkimVerified ? CheckStatus::PASSED : CheckStatus::PENDING,
                selector: $this->extractDkimSelector($name),
            );
        }

        // Pending DKIM (during key rotation) — separate record admin should add.
        if (!empty($domain['DKIMPendingHost']) && !empty($domain['DKIMPendingTextValue'])) {
            $name = (string) $domain['DKIMPendingHost'];
            $out[] = new DnsRecord(
                purpose:  RecordPurpose::DKIM,
                type:     'TXT',
                name:     $name,
                value:    (string) $domain['DKIMPendingTextValue'],
                status:   CheckStatus::PENDING,   // by definition during rotation
                selector: $this->extractDkimSelector($name),
            );
        }

        if (!empty($domain['ReturnPathDomain']) && !empty($domain['ReturnPathDomainCNAMEValue'])) {
            $out[] = new DnsRecord(
                purpose:  RecordPurpose::OWNERSHIP,
                type:     'CNAME',
                name:     (string) $domain['ReturnPathDomain'],
                value:    (string) $domain['ReturnPathDomainCNAMEValue'],
                status:   $rpVerified ? CheckStatus::PASSED : CheckStatus::PENDING,
            );
        }

        return $out;
    }

    private function extractDkimSelector(string $name): ?string
    {
        if (!preg_match('/^([^.]+)\._domainkey/i', $name, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Build Postmark JSON from Symfony Mime Email.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(\Symfony\Component\Mime\Email $message): array
    {
        $fromAddr  = $message->getFrom()[0] ?? null;
        $fromEmail = $fromAddr?->getAddress() ?? '';
        $fromName  = $fromAddr?->getName() ?: null;
        $from      = $fromName ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;

        // Postmark `To` is comma-separated string (max 50). We send 1 per call.
        $toList = [];
        foreach ($message->getTo() as $addr) {
            $toList[] = $addr->getName()
                ? sprintf('%s <%s>', $addr->getName(), $addr->getAddress())
                : $addr->getAddress();
        }

        $payload = [
            'From'    => $from,
            'To'      => implode(',', $toList),
            'Subject' => (string) $message->getSubject(),
            'MessageStream' => (string) ($this->config('postmark_message_stream', '') ?: 'outbound'),
        ];

        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        if ($html !== null) $payload['HtmlBody'] = $html;
        if ($text !== null) $payload['TextBody'] = $text;

        // Custom headers — preserve X-Acelle-* / X-Campaign-* etc.
        $skip = ['from','to','cc','bcc','reply-to','subject','content-type',
                 'content-transfer-encoding','mime-version','date','message-id','x-mailer'];
        $headers = [];
        foreach ($message->getHeaders()->all() as $h) {
            if (in_array(strtolower($h->getName()), $skip, true)) continue;
            $headers[] = ['Name' => $h->getName(), 'Value' => $h->getBodyAsString()];
        }
        if ($headers !== []) $payload['Headers'] = $headers;

        // Attachments — Postmark: [{Name, Content (base64), ContentType, ContentID(optional)}].
        $atts = [];
        foreach ($message->getAttachments() as $part) {
            $atts[] = [
                'Name'        => $part->getFilename(),
                'Content'     => base64_encode($part->getBody()),
                'ContentType' => $part->getContentType(),
            ];
        }
        if ($atts !== []) $payload['Attachments'] = $atts;

        $cc = $message->getCc();
        if ($cc) {
            $payload['Cc'] = implode(',', array_map(
                fn ($a) => $a->getName() ? sprintf('%s <%s>', $a->getName(), $a->getAddress()) : $a->getAddress(),
                $cc,
            ));
        }
        $bcc = $message->getBcc();
        if ($bcc) {
            $payload['Bcc'] = implode(',', array_map(
                fn ($a) => $a->getName() ? sprintf('%s <%s>', $a->getName(), $a->getAddress()) : $a->getAddress(),
                $bcc,
            ));
        }
        $replyTo = $message->getReplyTo();
        if ($replyTo) {
            $payload['ReplyTo'] = $replyTo[0]->getName()
                ? sprintf('%s <%s>', $replyTo[0]->getName(), $replyTo[0]->getAddress())
                : $replyTo[0]->getAddress();
        }

        return $payload;
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client(
                serverToken:  (string) $this->config('postmark_server_token'),
                accountToken: (string) ($this->config('postmark_account_token', '') ?: ''),
            );
        }
        return $this->client;
    }
}
