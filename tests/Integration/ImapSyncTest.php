<?php

namespace Cooolinho\FilamentMailbox\Tests\Integration;

use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * End-to-end synchronisation against a real IMAP server (GreenMail).
 * Each test uses a fresh GreenMail user so runs are independent.
 */
class ImapSyncTest extends TestCase
{
    protected string $username;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = @fsockopen($this->host(), $this->port(), $errno, $errstr, 1);

        if (! $connection) {
            $this->markTestSkipped("IMAP test server {$this->host()}:{$this->port()} not reachable.");
        }

        fclose($connection);

        Storage::fake('local');

        // A fresh GreenMail user per test keeps runs independent.
        $this->username = 'sync-'.Str::lower(Str::random(10)).'@example.com';

        $response = rescue(fn () => Http::timeout(5)->post($this->apiUrl('/api/user'), [
            'email' => $this->username,
            'login' => $this->username,
            'password' => 'secret',
        ]), report: false);

        if (! $response?->successful()) {
            $this->markTestSkipped('GreenMail REST API not reachable.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->username)) {
            rescue(fn () => Http::timeout(5)->delete($this->apiUrl('/api/user/'.$this->username)), report: false);
        }

        parent::tearDown();
    }

    public function test_full_synchronisation_cycle(): void
    {
        $client = $this->client();
        $delimiter = $client->inbox()->delimiter();
        $client->folders()->create('Customers');
        $client->folders()->create("Customers{$delimiter}Acme");
        $uid = $client->inbox()->messages()->append(file_get_contents(__DIR__.'/../Fixtures/mails/multipart.eml'));
        $client->disconnect();

        $mailbox = Mailbox::factory()->create([
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => Encryption::None,
            'validate_cert' => false,
            'username' => $this->username,
            'password' => 'secret',
        ]);

        $sync = $this->app->make(SyncService::class);

        $result = $sync->syncMailbox($mailbox);

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->imported);

        $folders = $mailbox->folders()->get()->keyBy('full_name');
        $this->assertSame(SpecialUse::Inbox, $folders['INBOX']->special_use);
        $this->assertSame($folders['Customers']->id, $folders["Customers{$delimiter}Acme"]->parent_id);
        $this->assertSame('Acme', $folders["Customers{$delimiter}Acme"]->name);

        $message = MailboxMessage::sole();
        $this->assertStringEndsWith(':'.$uid, $message->remote_id);
        $this->assertSame('Projekt Update ✓', $message->subject);
        $this->assertFalse($message->is_read);
        Storage::disk('local')->assertExists($message->attachments()->sole()->storage_path);

        // No duplicates on a second run.
        $this->assertSame(0, $sync->syncMailbox($mailbox)->imported);

        // Local actions are applied on the server.
        $this->app->make(MessageService::class)->markRead($message);

        $client = $this->client();
        $this->assertTrue($client->inbox()->messages()->withFlags()->find($uid)->isSeen());

        // Changes on the server are mirrored locally.
        $client->inbox()->messages()->find($uid)->markUnread();
        $client->disconnect();

        $sync->syncMailbox($mailbox);
        $this->assertFalse($message->refresh()->is_read);

        $this->app->make(MessageService::class)->delete($message);
        $this->assertSoftDeleted($message);

        $client = $this->client();
        $this->assertNull($client->inbox()->messages()->find($uid));
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
