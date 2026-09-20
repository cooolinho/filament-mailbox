<?php

namespace Cooolinho\FilamentMailbox\Tests\Integration;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Jobs\DeleteDraftFromServerJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Cooolinho\FilamentMailbox\Services\FolderManager;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Drafts stored with IMAP APPEND in GreenMail and linked by the synchronisation.
 */
class ImapDraftsTest extends TestCase
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

        $this->username = 'drafts-'.Str::lower(Str::random(10)).'@example.com';

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

    public function test_draft_versions_are_appended_replaced_linked_and_deleted(): void
    {
        $sync = app(SyncService::class);
        $drafts = app(DraftService::class);

        $sync->syncMailbox($this->mailbox);
        $folder = app(FolderManager::class)->create($this->mailbox, 'Drafts');
        $this->markAsDraftsFolder($folder);

        $draft = $drafts->save($this->mailbox, ['to' => ['jane@example.com'], 'subject' => 'Version 1', 'format' => 'text', 'body' => 'One'], null, $this->user);

        $this->assertTrue($drafts->pushToServer($draft->refresh()));
        $this->assertSame([['Version 1', true]], $this->serverDrafts());

        $this->syncDrafts($folder);

        $message = MailboxMessage::sole();
        $this->assertTrue($message->is_draft);
        $this->assertSame($draft->id, $message->draft_id);

        $draft = $drafts->save($this->mailbox, ['to' => ['jane@example.com'], 'subject' => 'Version 2', 'format' => 'text', 'body' => 'Two'], $draft, $this->user);
        $drafts->pushToServer($draft->refresh());

        $this->assertSame([['Version 2', true]], $this->serverDrafts());

        $this->syncDrafts($folder);

        $this->assertSame(1, MailboxMessage::count());
        $this->assertSame('Version 2', MailboxMessage::sole()->subject);
        $this->assertSame($draft->id, MailboxMessage::sole()->draft_id);

        $drafts->discard($draft->refresh());

        Queue::assertPushed(DeleteDraftFromServerJob::class, function (DeleteDraftFromServerJob $job): bool {
            $job->handle(app(DraftService::class));

            return true;
        });

        $this->assertSame([], $this->serverDrafts());
        $this->assertSame(0, MailboxDraft::count());
    }

    protected function markAsDraftsFolder(MailboxFolder $folder): void
    {
        // GreenMail folders have no special-use attributes.
        $folder->forceFill(['special_use' => SpecialUse::Drafts])->save();
    }

    protected function syncDrafts(MailboxFolder $folder): void
    {
        $provider = app(MailboxProviderFactory::class)->make($this->mailbox);

        try {
            app(SyncService::class)->syncFolder($folder->refresh(), $provider);
        } finally {
            $provider->disconnect();
        }
    }

    /**
     * @return array<int, array{0: ?string, 1: bool}>
     */
    protected function serverDrafts(): array
    {
        $client = $this->client();

        $messages = $client->folders()->findOrFail('Drafts')->messages()->withHeaders()->withFlags()->get()
            ->map(fn ($message): array => [$message->subject(), in_array('\\Draft', $message->flags(), true)])
            ->values()
            ->all();

        $client->disconnect();

        return $messages;
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
