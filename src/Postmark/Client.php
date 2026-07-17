<?php

namespace Acelle\Plugin\Postmark\Postmark;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal HTTP wrapper for Postmark API (https://postmarkapp.com/developer).
 *
 *   Base: https://api.postmarkapp.com
 *   Auth — TWO separate token types:
 *     - Server token  → header `X-Postmark-Server-Token`
 *       Per-server, scoped to ONE Postmark "Server" (== sender pool).
 *       Used by: /email, /webhooks (per-server), /messages, /stats, etc.
 *     - Account token → header `X-Postmark-Account-Token`
 *       Account-wide. Used by: /domains, /senders, /servers.
 *
 * We expose both ::request() variants (`withServerToken`, `withAccountToken`)
 * so callsites pick the right scope explicitly.
 *
 * Postmark returns 200/201 on success with JSON body. Errors come back as
 *   {ErrorCode: <int>, Message: "...", ...}  with HTTP 422 typically.
 * We surface `ErrorCode + Message` verbatim in the exception.
 */
class Client
{
    public const BASE_URL = 'https://api.postmarkapp.com';

    public function __construct(
        private readonly ?string $serverToken,
        private readonly ?string $accountToken = null,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     * @throws Error
     */
    public function serverRequest(string $method, string $path, ?array $body = null): array
    {
        if ($this->serverToken === null || $this->serverToken === '') {
            throw new Error('Postmark server token required for ' . $method . ' ' . $path);
        }
        return $this->request($method, $path, $body, 'X-Postmark-Server-Token', $this->serverToken);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     * @throws Error
     */
    public function accountRequest(string $method, string $path, ?array $body = null): array
    {
        if ($this->accountToken === null || $this->accountToken === '') {
            throw new Error('Postmark account token required for ' . $method . ' ' . $path
                . ' — set postmark_account_token to use domains/senders endpoints');
        }
        return $this->request($method, $path, $body, 'X-Postmark-Account-Token', $this->accountToken);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     * @throws Error
     */
    private function request(string $method, string $path, ?array $body, string $tokenHeader, string $token): array
    {
        $url = self::BASE_URL . '/' . ltrim($path, '/');

        $options = [
            'headers' => [
                $tokenHeader   => $token,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
            'timeout'     => 30,
        ];
        if ($body !== null) {
            $options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = (new Guzzle())->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new Error('Postmark API request failed: ' . $e->getMessage(), 0, 0, $e);
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $status  = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $json    = json_decode($rawBody, true);

        if ($status >= 200 && $status < 300) {
            return is_array($json) ? $json : [];
        }

        // Postmark error shape: {"ErrorCode": 10, "Message": "..."}
        $msg = is_array($json)
            ? trim(($json['ErrorCode'] !== null ? '[code ' . $json['ErrorCode'] . '] ' : '') . ($json['Message'] ?? $rawBody))
            : ($rawBody !== '' ? $rawBody : 'HTTP ' . $status);
        $errCode = is_array($json) ? (int) ($json['ErrorCode'] ?? 0) : 0;

        throw new Error(sprintf('Postmark API %d: %s', $status, $msg), $status, $errCode);
    }
}
