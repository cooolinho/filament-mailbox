<?php

namespace Cooolinho\FilamentMailbox\Tests\Integration;

use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Services\FolderManager;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Folder management against GreenMail with a fresh user per test.
 */
class ImapFolderManagementTest extends TestCase
{
    protected string $username;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = @fsockopen($this->host(), $this->port(), $errno, $errstr, 1);

        if (! $connection) {
            $this->markTestSkipped("IMAP test server {$this->host()}:{$this->port()} not reachable.");
        }

        fclose($connection);

        $this->username = 'folders-'.Str::lower(Str::random(10)).'@example.com';

        $response = rescue(fn () => Http::timeout(5)->post($this->apiUrl('/api/user'), [
            'email' => $this->username,
            'login' => $this->username,
            'password' => 'secret',
        ]), report: false);

        if (! $response?->successful()) {
            $this->markTestSkipped('GreenMail REST API not reachable.');
        }

        Queue::fake();

        $this->mailbox = Mailbox::factory()->create([
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => Encryption::None,
            'validate_cert' => false,
            'username' => $this->username,
            'password' => 'secret',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->username)) {
            rescue(fn () => Http::timeout(5)->delete($this->apiUrl('/api/user/'.$this->username)), report: false);
        }

        parent::tearDown();
    }

    public function test_folders_are_created_renamed_subscribed_and_deleted_on_the_server(): void
    {
        $sync = app(SyncService::class);
        $manager = app(FolderManager::class);

        $sync->syncMailbox($this->mailbox);

        $projects = $manager->create($this->mailbox, 'Projekte');
        $drafts = $manager->create($this->mailbox, 'Entwürfe', $projects);
        $delimiter = $projects->refresh()->delimiter ?? '.';

        $this->assertSame("Projekte{$delimiter}Entw&APw-rfe", $drafts->full_name);
        $this->assertSame('Entwürfe', $drafts->name);
        $this->assertContains("Projekte{$delimiter}Entw&APw-rfe", $this->serverFolders());

        // Folders with non-ASCII names can be synchronised (no double encoding).
        $this->appendTo($drafts->full_name);
        $result = $sync->syncMailbox($this->mailbox);

        $this->assertTrue($result->successful(), implode(', ', $result->errors));
        $this->assertSame(1, $drafts->refresh()->messages()->count());
        $this->assertSame(1, $drafts->message_count);
        $this->assertTrue($drafts->is_subscribed);
        $this->assertSame(3, $this->mailbox->folders()->count());

        $manager->rename($projects->refresh(), 'Kunden');

        $this->assertSame("Kunden{$delimiter}Entw&APw-rfe", $drafts->refresh()->full_name);
        $this->assertContains("Kunden{$delimiter}Entw&APw-rfe", $this->serverFolders());
        $this->assertNotContains('Projekte', $this->serverFolders());

        $sync->syncMailbox($this->mailbox);

        $this->assertSame(3, $this->mailbox->folders()->count());
        $this->assertSame(3, $this->mailbox->folders()->where('is_active', true)->count());
        $this->assertSame(1, $drafts->refresh()->messages()->count());

        $manager->subscribe($drafts, false);
        $sync->syncFolders($this->mailbox, app(\Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory::class)->make($this->mailbox));

        $this->assertFalse($drafts->refresh()->is_subscribed);

        config(['filament-mailbox.folders.allow_permanent_delete' => true]);
        $manager->delete($projects->refresh(), FolderManager::DELETE_PERMANENTLY);

        $this->assertSame(['INBOX'], $this->serverFolders());

        $sync->syncMailbox($this->mailbox);

        $this->assertSame(1, $this->mailbox->folders()->where('is_active', true)->count());
        $this->assertSame(0, MailboxFolder::where('is_active', true)->where('full_name', 'like', 'Kunden%')->count());
    }

    /**
     * @return array<int, string>
     */
    protected function serverFolders(): array
    {
        $client = $this->client();
        $paths = $client->connection()->list('', '*')->map(fn ($response) => (string) $response->tokenAt(4)->value)->sort()->values()->all();
        $client->disconnect();

        return $paths;
    }

    protected function appendTo(string $path): void
    {
        $client = $this->client();
        $client->connect();
        $client->connection()->append($path, file_get_contents(__DIR__.'/../Fixtures/mails/multipart.eml'));
        $client->disconnect();
    }

    protected function client(): ImapMailbox
    {
        $client = new ImapMailbox([
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => null,
            'username' => $this->username,
            'password' => 'secret',
        ]);
        $client->connect();

        return $client;
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
