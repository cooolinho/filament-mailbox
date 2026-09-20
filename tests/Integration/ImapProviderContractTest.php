<?php

namespace Cooolinho\FilamentMailbox\Tests\Integration;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider;
use Cooolinho\FilamentMailbox\Tests\Contracts\MailboxProviderContractTest;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Runs the provider contract against GreenMail with a fresh user per test.
 */
class ImapProviderContractTest extends MailboxProviderContractTest
{
    protected string $username;

    protected ?ImapProvider $provider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = @fsockopen($this->host(), $this->port(), $errno, $errstr, 1);

        if (! $connection) {
            $this->markTestSkipped("IMAP test server {$this->host()}:{$this->port()} not reachable.");
        }

        fclose($connection);

        $this->username = 'contract-'.Str::lower(Str::random(10)).'@example.com';

        $response = rescue(fn () => Http::timeout(5)->post($this->apiUrl('/api/user'), [
            'email' => $this->username,
            'login' => $this->username,
            'password' => 'secret',
        ]), report: false);

        if (! $response?->successful()) {
            $this->markTestSkipped('GreenMail REST API not reachable.');
        }

        $client = $this->client();
        $client->folders()->create('Archive');
        $client->disconnect();
    }

    protected function tearDown(): void
    {
        $this->provider?->disconnect();

        if (isset($this->username)) {
            rescue(fn () => Http::timeout(5)->delete($this->apiUrl('/api/user/'.$this->username)), report: false);
        }

        parent::tearDown();
    }

    protected function provider(): MailboxProvider
    {
        return $this->provider ??= new ImapProvider(Mailbox::factory()->make([
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => Encryption::None,
            'validate_cert' => false,
            'username' => $this->username,
            'password' => 'secret',
        ]));
    }

    protected function inbox(): FolderIdentifier
    {
        return new FolderIdentifier('INBOX');
    }

    protected function otherFolder(): FolderIdentifier
    {
        return new FolderIdentifier('Archive');
    }

    protected function seedMessage(FolderIdentifier $folder, string $rawMime): void
    {
        $client = $this->client();
        $client->folders()->findOrFail($folder->remoteId)->messages()->append($rawMime);
        $client->disconnect();
    }

    protected function client(): ImapMailbox
    {
        return new ImapMailbox([
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => null,
            'username' => $this->username,
            'password' => 'secret',
        ]);
    }

    protected function apiUrl(string $path): string
    {
        return rtrim((string) env('MAILBOX_TEST_GREENMAIL_API', 'http://'.$this->host().':8080'), '/').$path;
    }

    protected function host(): string
    {
        return (string) env('MAILBOX_TEST_IMAP_HOST', 'sandbox-greenmail');
    }

    protected function port(): int
    {
        return (int) env('MAILBOX_TEST_IMAP_PORT', 3143);
    }
}
