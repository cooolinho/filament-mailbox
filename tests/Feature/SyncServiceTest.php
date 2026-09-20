<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\AddressData;
use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\FolderSynced;
use Cooolinho\FilamentMailbox\Events\FolderSyncFailed;
use Cooolinho\FilamentMailbox\Events\MailboxSynced;
use Cooolinho\FilamentMailbox\Events\MailboxSyncFailed;
use Cooolinho\FilamentMailbox\Events\MessagesImported;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SyncServiceTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->provider = $this->fakeProvider();
        $this->mailbox = Mailbox::factory()->create(['password' => 'hunter2']);

        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox), uidValidity: 100)
            ->addFolder(new FolderData('Gesendet', 'Gesendet', '/', SpecialUse::Sent), uidValidity: 200)
            ->addFolder(new FolderData('Customers', 'Customers', '/'))
            ->addFolder(new FolderData('Acme', 'Customers/Acme', '/', null, 'Customers'));
    }

    public function test_it_mirrors_the_folder_structure(): void
    {
        $this->sync();

        $folders = $this->mailbox->folders()->get()->keyBy('full_name');

        $this->assertCount(4, $folders);
        $this->assertSame(SpecialUse::Inbox, $folders['INBOX']->special_use);
        $this->assertSame(SpecialUse::Sent, $folders['Gesendet']->special_use);
        $this->assertNull($folders['Customers']->special_use);
        $this->assertSame($folders['Customers']->id, $folders['Customers/Acme']->parent_id);
        $this->assertSame('Acme', $folders['Customers/Acme']->name);
        $this->assertSame('INBOX', $folders['INBOX']->remote_id);
        $this->assertEquals(['uid_validity' => 100, 'last_uid' => 0], $folders['INBOX']->sync_cursor);
    }

    public function test_it_imports_new_messages_with_attachments(): void
    {
        $this->provider->addMessage('INBOX', $this->message(1, attachments: [
            new AttachmentData('../../etc/passwd', 'text/plain', 'file contents'),
        ]));

        $result = $this->sync();

        $this->assertSame(1, $result->imported);

        $message = MailboxMessage::sole();
        $this->assertSame('Subject 1', $message->subject);
        $this->assertSame('john@example.com', $message->from_address);
        // MySQL normalises the key order of JSON objects.
        $this->assertEquals([['address' => 'jane@example.com', 'name' => 'Jane']], $message->to);
        $this->assertSame('INBOX', $message->folder->full_name);
        $this->assertSame('100:1', $message->remote_id);
        $this->assertTrue($message->has_attachments);

        $attachment = $message->attachments()->sole();
        $this->assertSame('passwd', $attachment->filename);
        $this->assertSame(13, $attachment->size);
        $this->assertStringNotContainsString('passwd', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);

        $this->assertSame(1, MailboxFolder::where('full_name', 'INBOX')->sole()->cursor()->get('last_uid'));
    }

    public function test_repeated_sync_does_not_duplicate_messages(): void
    {
        $this->provider->addMessage('INBOX', $this->message(1));
        $this->provider->addMessage('INBOX', $this->message(2));

        $this->sync();
        $result = $this->sync();

        $this->assertSame(0, $result->imported);
        $this->assertSame(2, MailboxMessage::count());
    }

    public function test_it_fetches_messages_in_chunks_and_incrementally(): void
    {
        config(['filament-mailbox.sync.chunk_size' => 2]);

        foreach ([1, 2, 3, 4, 5] as $uid) {
            $this->provider->addMessage('INBOX', $this->message($uid));
        }

        $this->assertSame(5, $this->sync()->imported);

        $this->provider->addMessage('INBOX', $this->message(9));

        $this->assertSame(1, $this->sync()->imported);
        $this->assertSame(9, MailboxFolder::where('full_name', 'INBOX')->sole()->cursor()->get('last_uid'));
    }

    public function test_uid_validity_change_resets_the_folder(): void
    {
        $this->provider->addMessage('INBOX', $this->message(1, subject: 'Old'));
        $this->sync();

        $this->provider->messages['INBOX'] = [];
        $this->provider->uidValidity['INBOX'] = 101;
        $this->provider->addMessage('INBOX', $this->message(1, subject: 'New'));

        $result = $this->sync();

        $this->assertSame(1, $result->imported);
        $this->assertSame(['New'], MailboxMessage::pluck('subject')->all());
        $this->assertSame(1, MailboxMessage::onlyTrashed()->count());
        $this->assertSame(101, MailboxFolder::where('full_name', 'INBOX')->sole()->cursor()->get('uid_validity'));
        $this->assertSame('101:1', MailboxMessage::sole()->remote_id);
    }

    public function test_it_synchronises_read_state_and_server_deletions(): void
    {
        $this->provider->addMessage('INBOX', $this->message(1, seen: false));
        $this->provider->addMessage('INBOX', $this->message(2, seen: true));
        $this->provider->addMessage('INBOX', $this->message(3));
        $this->sync();

        $this->provider->setSeen('INBOX', 1, true);
        $this->provider->setSeen('INBOX', 2, false);
        unset($this->provider->messages['INBOX'][3]);

        $this->sync();

        $this->assertTrue(MailboxMessage::where('remote_id', '100:1')->value('is_read'));
        $this->assertFalse(MailboxMessage::where('remote_id', '100:2')->value('is_read'));
        $this->assertSoftDeleted(MailboxMessage::withTrashed()->where('remote_id', '100:3')->first());
    }

    public function test_removed_folders_are_deactivated_not_deleted(): void
    {
        $this->provider->addMessage('Customers', $this->message(1));
        $this->sync();

        $this->provider->removeFolder('Customers');
        $this->sync();

        $folder = MailboxFolder::where('full_name', 'Customers')->sole();

        $this->assertFalse($folder->is_active);
        $this->assertSame(1, $folder->messages()->count());
    }

    public function test_connection_failure_is_recorded_without_credentials(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->provider->connectionException = new RuntimeException('LOGIN failed for password hunter2');

        try {
            $this->sync();
            $this->fail('Expected SyncFailed.');
        } catch (SyncFailed $exception) {
            $this->assertStringNotContainsString('hunter2', $exception->getMessage());
        }

        $this->mailbox->refresh();

        $this->assertStringContainsString('LOGIN failed', $this->mailbox->last_sync_error);
        $this->assertStringNotContainsString('hunter2', $this->mailbox->last_sync_error);
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('hunter2', implode("\n", $logged));
        $this->assertTrue($this->provider->disconnected);
    }

    public function test_a_failing_folder_does_not_stop_the_others(): void
    {
        $this->provider->addMessage('INBOX', $this->message(1));
        $this->provider->addFolder(new FolderData('Broken', 'Broken', '/'));

        $provider = new class extends FakeMailboxProvider
        {
            public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
            {
                if ($folder->remoteId === 'Broken') {
                    throw new ConnectionFailed('Folder not selectable');
                }

                return parent::changes($folder, $cursor, $limit);
            }
        };
        $provider->folders = $this->provider->folders;
        $provider->uidValidity = $this->provider->uidValidity;
        $provider->messages = $this->provider->messages;
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($provider));

        $result = $this->app->make(SyncService::class)->syncMailbox($this->mailbox);

        $this->assertSame(1, $result->imported);
        $this->assertSame(['Broken' => 'Folder not selectable'], $result->errors);
        $this->assertStringContainsString('Broken: Folder not selectable', $this->mailbox->refresh()->last_sync_error);
        $this->assertNotNull($this->mailbox->last_synced_at);
    }

    public function test_it_synchronises_flagged_answered_and_keywords(): void
    {
        $this->provider->addMessage('INBOX', $this->message(1, flags: new MessageFlags(flagged: true, keywords: ['$label1'])));
        $this->sync();

        $message = MailboxMessage::sole();
        $this->assertTrue($message->is_flagged);
        $this->assertFalse($message->is_answered);
        $this->assertSame(['$label1'], $message->keywords);

        $this->provider->messages['INBOX'][1] = $this->provider->messages['INBOX'][1]->with([
            'flags' => new MessageFlags(seen: true, answered: true),
        ]);
        $this->sync();

        $message->refresh();
        $this->assertTrue($message->is_read);
        $this->assertFalse($message->is_flagged);
        $this->assertTrue($message->is_answered);
        $this->assertNull($message->keywords);
    }

    public function test_delta_style_changes_are_applied(): void
    {
        $provider = new class extends FakeMailboxProvider
        {
            public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
            {
                if ($folder->remoteId !== 'INBOX') {
                    return new FolderChanges([], [], [], $cursor);
                }

                return match ($cursor->get('token')) {
                    null => new FolderChanges(
                        created: [new MessageData('a', subject: 'A'), new MessageData('b', subject: 'B')],
                        updated: [],
                        deleted: [],
                        cursor: new SyncCursor(['token' => 'page-2']),
                        hasMore: true,
                    ),
                    'page-2' => new FolderChanges(
                        created: [new MessageData('c', subject: 'C')],
                        updated: [],
                        deleted: [],
                        cursor: new SyncCursor(['token' => 'delta-1']),
                    ),
                    'delta-1' => new FolderChanges(
                        created: [],
                        updated: ['a' => new MessageFlags(seen: true, flagged: true)],
                        deleted: ['b'],
                        cursor: new SyncCursor(['token' => 'delta-2']),
                    ),
                    default => new FolderChanges(
                        created: [new MessageData('b', subject: 'B again'), new MessageData('a', subject: 'A')],
                        updated: [],
                        deleted: [],
                        cursor: new SyncCursor(['token' => 'delta-3']),
                        reset: true,
                    ),
                };
            }
        };
        $provider->folders = $this->provider->folders;
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($provider));

        $this->assertSame(3, $this->sync()->imported);
        $this->assertSame(['token' => 'delta-1'], MailboxFolder::where('remote_id', 'INBOX')->sole()->sync_cursor);

        $this->assertSame(0, $this->sync()->imported);
        $a = MailboxMessage::where('remote_id', 'a')->sole();
        $this->assertTrue($a->is_read);
        $this->assertTrue($a->is_flagged);
        $this->assertSoftDeleted(MailboxMessage::withTrashed()->where('remote_id', 'b')->sole());

        // A reset soft-deletes all local messages; re-delivered ones are restored, not duplicated.
        $this->assertSame(2, $this->sync()->imported);
        $this->assertEqualsCanonicalizing(['a', 'b'], MailboxMessage::pluck('remote_id')->all());
        $this->assertSoftDeleted(MailboxMessage::withTrashed()->where('remote_id', 'c')->sole());
        $this->assertSame(3, MailboxMessage::withTrashed()->count());
    }

    public function test_sync_events_are_dispatched(): void
    {
        Event::fake([MessagesImported::class, FolderSynced::class, FolderSyncFailed::class, MailboxSynced::class, MailboxSyncFailed::class]);

        $this->provider->addMessage('INBOX', $this->message(1));
        $this->sync();

        Event::assertDispatched(MessagesImported::class, fn (MessagesImported $event): bool => $event->folder->remote_id === 'INBOX' && $event->count === 1);
        Event::assertDispatchedTimes(FolderSynced::class, 4);
        Event::assertDispatched(MailboxSynced::class, fn (MailboxSynced $event): bool => $event->result->imported === 1);
        Event::assertNotDispatched(FolderSyncFailed::class);

        $this->provider->connectionException = new RuntimeException('down');

        try {
            $this->sync();
        } catch (SyncFailed) {
        }

        Event::assertDispatched(MailboxSyncFailed::class, fn (MailboxSyncFailed $event): bool => $event->error === 'down');
    }

    protected function sync(): SyncResult
    {
        return $this->app->make(SyncService::class)->syncMailbox($this->mailbox);
    }

    /**
     * @param  array<int, AttachmentData>  $attachments
     */
    protected function message(int $uid, ?string $subject = null, bool $seen = false, array $attachments = [], ?MessageFlags $flags = null): MessageData
    {
        return new MessageData(
            remoteId: (string) $uid,
            messageId: "message-{$uid}@example.com",
            from: new AddressData('john@example.com', 'John'),
            to: [new AddressData('jane@example.com', 'Jane')],
            subject: $subject ?? "Subject {$uid}",
            textBody: "Body {$uid}",
            flags: $flags ?? new MessageFlags(seen: $seen),
            sentAt: now(),
            attachments: $attachments,
        );
    }
}
