<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Livewire\Livewire;

class ProviderFoundationTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);

        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox), uidValidity: 1)
            ->addFolder(new FolderData('Archive', 'Archive', '/'), uidValidity: 1);

        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->archive = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Archive', 'full_name' => 'Archive']);
    }

    public function test_mvp_data_is_migrated_without_reimport(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_18_000001_generalize_mailbox_sync_identifiers.php';
        // Later migrations index the new columns.
        $gmail = require __DIR__.'/../../database/migrations/2026_09_18_000005_add_gmail_provider_support.php';
        $starred = require __DIR__.'/../../database/migrations/2026_09_19_000002_add_starred_index_to_mailbox_messages.php';
        $starred->down();
        $gmail->down();
        $migration->down();

        $folderId = DB::table('mailbox_folders')->insertGetId([
            'mailbox_id' => $this->mailbox->id, 'name' => 'Legacy', 'full_name' => 'Legacy', 'delimiter' => '/',
            'uid_validity' => 100, 'last_synced_uid' => 2, 'is_active' => true,
        ]);

        foreach ([1, 2] as $uid) {
            DB::table('mailbox_messages')->insert([
                'mailbox_id' => $this->mailbox->id, 'folder_id' => $folderId, 'uid' => $uid, 'uid_validity' => 100,
                'subject' => "Legacy {$uid}", 'is_read' => $uid === 1, 'has_attachments' => false,
            ]);
        }

        $migration->up();
        $gmail->up();
        $starred->up();

        $folder = MailboxFolder::findOrFail($folderId);
        $this->assertSame('Legacy', $folder->remote_id);
        $this->assertEquals(['uid_validity' => 100, 'last_uid' => 2], $folder->sync_cursor);
        $this->assertSame(['100:1', '100:2'], $folder->messages()->orderBy('id')->pluck('remote_id')->all());

        $this->provider->addFolder(new FolderData('Legacy', 'Legacy', '/'), uidValidity: 100);
        $this->provider->addMessage('Legacy', new MessageData('1', subject: 'Legacy 1'));
        $this->provider->addMessage('Legacy', new MessageData('2', subject: 'Legacy 2'));
        $this->provider->addMessage('Legacy', new MessageData('3', subject: 'New'));

        $this->assertSame(1, $this->app->make(SyncService::class)->syncFolder($folder, $this->provider));
        $this->assertSame(3, $folder->messages()->count());
        $this->assertFalse(MailboxMessage::where('remote_id', '100:1')->value('is_read'));
    }

    public function test_default_factory_resolves_providers_from_the_registry(): void
    {
        $factory = $this->app->make(DefaultMailboxProviderFactory::class);

        $this->assertInstanceOf(ImapProvider::class, $factory->make($this->mailbox));

        config(['filament-mailbox.providers.imap' => stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        $factory->make($this->mailbox);
    }

    public function test_imap_capabilities_do_not_need_a_connection(): void
    {
        $this->app->forgetInstance(MailboxProviderFactory::class);
        $this->app->bind(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $mailbox = Mailbox::factory()->make(['host' => 'unreachable.invalid']);

        $this->assertTrue($mailbox->supports(ProviderCapability::MoveMessages));
        $this->assertFalse($mailbox->supports(ProviderCapability::Labels));
    }

    public function test_actions_are_hidden_without_capability(): void
    {
        $this->provider->capabilities = [ProviderCapability::Folders];
        $unread = $this->message(['is_read' => false]);
        $read = $this->message(['is_read' => true]);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertActionHidden(TestAction::make('markAsRead')->table($unread))
            ->assertActionHidden(TestAction::make('markAsUnread')->table($read))
            ->assertActionVisible(TestAction::make('deleteMessage')->table($read));

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $read->id])
            ->assertActionHidden('markAsUnread');
    }

    public function test_unsupported_operation_shows_a_warning(): void
    {
        $this->provider->capabilities = [ProviderCapability::Folders];
        $messages = MailboxMessage::factory()->count(2)->for($this->inbox, 'folder')->create(['is_read' => false]);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->selectTableRecords($messages)
            ->callAction(TestAction::make('markAsRead')->table()->bulk())
            ->assertNotified(__('filament-mailbox::mailbox.actions.unsupported'));

        $this->assertSame(0, MailboxMessage::where('is_read', true)->count());
    }

    public function test_messages_are_moved_with_their_new_identity(): void
    {
        $message = $this->remoteMessage();

        $this->app->make(MessageService::class)->move($message, $this->archive);

        $message->refresh();
        $this->assertSame($this->archive->id, $message->folder_id);
        $this->assertSame('1:1', $message->remote_id);
        $this->assertSame([], $this->provider->messages['INBOX']);
        $this->assertArrayHasKey(1, $this->provider->messages['Archive']);
    }

    public function test_moved_messages_without_new_identity_are_imported_with_the_next_sync(): void
    {
        $provider = new class extends FakeMailboxProvider
        {
            public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier
            {
                parent::moveMessage($message, $target);

                return null;
            }
        };
        $provider->folders = $this->provider->folders;
        $provider->uidValidity = $this->provider->uidValidity;
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($provider));
        $this->provider = $provider;

        $message = $this->remoteMessage();

        $this->app->make(MessageService::class)->move($message, $this->archive);

        $this->assertSoftDeleted($message);
        $this->assertSame(1, $this->app->make(SyncService::class)->syncFolder($this->archive, $provider));
    }

    public function test_moving_is_rejected_without_capability_or_across_mailboxes(): void
    {
        $message = $this->remoteMessage();
        $foreign = MailboxFolder::factory()->create();

        try {
            $this->app->make(MessageService::class)->move($message, $foreign);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        $this->provider->capabilities = [ProviderCapability::Folders];

        $this->expectException(UnsupportedOperation::class);
        $this->app->make(MessageService::class)->move($message, $this->archive);
    }

    public function test_messages_can_be_flagged(): void
    {
        $message = $this->remoteMessage();
        $service = $this->app->make(MessageService::class);

        $service->setFlagged($message, true);

        $this->assertTrue($message->refresh()->is_flagged);
        $this->assertTrue($this->provider->messages['INBOX'][1]->flags->flagged);

        $service->setFlagged($message, false);

        $this->assertFalse($message->refresh()->is_flagged);
        $this->assertFalse($this->provider->messages['INBOX'][1]->flags->flagged);
    }

    /**
     * A message that exists both locally and in the fake provider.
     */
    protected function remoteMessage(): MailboxMessage
    {
        $this->provider->addMessage('INBOX', new MessageData('1', subject: 'Remote'));

        return $this->message(['remote_id' => '1:1']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function message(array $attributes = []): MailboxMessage
    {
        return MailboxMessage::factory()->for($this->inbox, 'folder')->create($attributes);
    }
}
