<?php

declare(strict_types=1);

use Acelle\Plugin\Postmark\Postmark\Client;
use Acelle\Plugin\Postmark\Postmark\Error;
use Acelle\Plugin\Postmark\PostmarkDriver;
use App\Model\SendingServer;
use App\SendingServers\Identities\IdentityKind;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Postmark lists are read to their TotalCount (they were cut at 500 rows), a
 * failed read fails the sync instead of being skipped, and a lookup without the
 * account token is an error, not "not found" (which archived the identity).
 */
function postmarkDriverAnswering(\Closure $get, string $accountToken = 'account-token'): PostmarkDriver
{
    $server = new SendingServer(['type' => PostmarkDriver::TYPE, 'name' => 'Postmark fixture']);
    $server->setConfigMany(['postmark_server_token' => 'server-token', 'postmark_account_token' => $accountToken]);
    $driver = new PostmarkDriver($server);
    $client = new class($get) extends Client {
        public function __construct(private readonly \Closure $answer)
        {
        }

        public function accountRequest(string $method, string $path, ?array $body = null): array
        {
            return ($this->answer)($path);
        }
    };
    (new ReflectionProperty($driver, 'client'))->setValue($driver, $client);

    return $driver;
}

it('reads every page of the sender list', function () {
    $sender = fn (string $email) => ['EmailAddress' => $email, 'Confirmed' => true];
    $driver = postmarkDriverAnswering(fn (string $path) => match ($path) {
        'senders?count=500&offset=0' => ['TotalCount' => 3, 'SenderSignatures' => [$sender('a@example.test'), $sender('b@example.test')]],
        'senders?count=500&offset=2' => ['TotalCount' => 3, 'SenderSignatures' => [$sender('c@example.test')]],
        'domains?count=500&offset=0' => ['TotalCount' => 0, 'Domains' => []],
    });

    expect(array_map(fn ($i) => $i->value, $driver->fetchIdentities()))->toBe(['a@example.test', 'b@example.test', 'c@example.test']);
});

it('fails the sync when a list cannot be read', function () {
    $driver = postmarkDriverAnswering(fn () => throw new Error('Postmark 500', 500));

    $driver->fetchIdentities();
})->throws(Error::class, 'Postmark 500');

it('refuses a lookup without the account token instead of calling the identity gone', function () {
    $driver = postmarkDriverAnswering(fn () => throw new LogicException('no call expected'), accountToken: '');

    $driver->fetchIdentity(IdentityKind::EMAIL, 'a@example.test');
})->throws(RuntimeException::class, 'has no account token');
