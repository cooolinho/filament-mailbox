<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessagesArchived;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\ProviderCapabilities;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class ArchiveTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Archiv', 'Archiv', '/', SpecialUse::Archive));

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->archive = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Archive)->create(['name' => 'Archiv', 'full_name' => 'Archiv']);
    }

    public function test_all_mail_is_not_an_archive(): void
    {
        $this->assertSame(SpecialUse::All, SpecialUse::fromImapFlag('\All'));
        $this->assertSame(SpecialUse::Archive, SpecialUse::fromImapFlag('\Archive'));
    }

    public function test_archive_moves_the_message_on_the_server_and_locally(): void
    {
        Event::fake([MessagesArchived::class]);

        $message = $this->message(1);

        $moves = app(MessageService::class)->archive($message);

        $this->assertSame([$message->id => $this->inbox->id], $moves);
        $this->assertSame('moveMessage', $this->provider->calls[0][0]);
        $this->assertSame('Archiv', $this->provider->calls[0][2]->remoteId);

        $message->refresh();
        $this->assertSame($this->archive->id, $message->folder_id);
        $this->assertSame('1:1', $message->remote_id);
        $this->assertArrayHasKey(1, $this->provider->messages['Archiv']);

        Event::assertDispatched(MessagesArchived::class, fn (MessagesArchived $event): bool => $event->messageIds === [$message->id]);
    }

    public function test_messages_already_archived_or_in_the_trash_are_skipped(): void
    {
        $trash = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Trash)->create(['name' => 'Trash', 'full_name' => 'Trash']);
        $archived = MailboxMessage::factory()->for($this->archive, 'folder')->create();
        $trashed = MailboxMessage::factory()->for($trash, 'folder')->create();

        $this->assertSame([], app(MessageService::class)->archive([$archived, $trashed]));
        $this->assertSame([], $this->provider->calls);
    }

    public function test_configured_archive_folder_is_used_without_special_use(): void
    {
        $this->archive->forceFill(['special_use' => null])->save();
        $message = $this->message(1);

        $this->assertFalse(app(MessageService::class)->canArchive($message));

        $foreign = MailboxFolder::factory()->create();
        $this->mailbox->forceFill(['archive_folder_id' => $foreign->id])->save();
        $this->assertNull($this->mailbox->refresh()->archiveFolder());

        $this->mailbox->forceFill(['archive_folder_id' => $this->archive->id])->save();
        app(MessageService::class)->archive($message->refresh());

        $this->assertSame($this->archive->id, $message->refresh()->folder_id);
    }

    public function test_missing_archive_folder_is_created_on_the_first_archiving(): void
    {
        $this->archive->forceFill(['special_use' => null, 'is_active' => false])->save();
        $this->provider->removeFolder('Archiv');
        $message = $this->message(1);
        $second = $this->message(2);

        $this->assertFalse(app(MessageService::class)->canArchive($message));

        config(['filament-mailbox.archive.create_folder_if_missing' => true, 'filament-mailbox.archive.folder_name' => 'Erledigt']);

        $this->assertTrue(app(MessageService::class)->canArchive($message));
        $this->browse()->assertActionEnabled(TestAction::make('archive')->table($message));

        app(MessageService::class)->archive([$message, $second]);

        $folder = $this->mailbox->refresh()->archiveFolder();
        $this->assertSame('Erledigt', $folder->full_name);
        $this->assertArrayHasKey('Erledigt', $this->provider->folders);
        $this->assertSame([$folder->id, $folder->id], [$message->refresh()->folder_id, $second->refresh()->folder_id]);
        $this->assertCount(1, array_filter($this->provider->calls, fn (array $call): bool => $call[0] === 'createFolder'));
    }

    public function test_an_existing_folder_with_the_configured_name_is_used(): void
    {
        $this->archive->forceFill(['special_use' => null])->save();
        config(['filament-mailbox.archive.create_folder_if_missing' => true, 'filament-mailbox.archive.folder_name' => 'archiv']);

        app(MessageService::class)->archive($message = $this->message(1));

        $this->assertSame($this->archive->id, $message->refresh()->folder_id);
        $this->assertSame($this->archive->id, $this->mailbox->refresh()->archive_folder_id);
        $this->assertSame([], array_filter($this->provider->calls, fn (array $call): bool => $call[0] === 'createFolder'));
    }

    public function test_archive_folder_is_not_created_without_folder_management(): void
    {
        $this->archive->forceFill(['special_use' => null])->save();
        config(['filament-mailbox.archive.create_folder_if_missing' => true, 'filament-mailbox.folders.management' => false]);

        $this->assertFalse(app(MessageService::class)->canArchive($this->message(1)));
    }

    public function test_moved_message_without_new_identity_is_restored_by_the_next_sync(): void
    {
        $message = $this->message(1, ['remote_id' => '0:1', 'message_id' => '<invoice@example.com>', 'has_attachments' => true]);
        $attachment = MailboxAttachment::factory()->for($message, 'message')->create();

        // The stale identifier cannot be addressed on the server: the move is only mirrored locally.
        app(MessageService::class)->archive($message);

        $this->assertSoftDeleted($message);
        $this->assertSame($this->archive->id, MailboxMessage::withTrashed()->find($message->id)->pending_move_to);

        $this->provider->addMessage('Archiv', new MessageData('7', messageId: '<invoice@example.com>', subject: 'Invoice'));
        app(SyncService::class)->syncFolder($this->archive, $this->provider);

        $restored = MailboxMessage::sole();
        $this->assertSame($message->id, $restored->id);
        $this->assertSame($this->archive->id, $restored->folder_id);
        $this->assertSame('1:7', $restored->remote_id);
        $this->assertNull($restored->pending_move_to);
        $this->assertSame([$attachment->id], MailboxAttachment::pluck('id')->all());
    }

    public function test_row_action_archives_and_offers_undo(): void
    {
        $message = $this->message(1);

        $this->browse()
            ->callAction(TestAction::make('archive')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.archive.success'))
            ->assertCanNotSeeTableRecords([$message]);

        $this->assertSame($this->archive->id, $message->refresh()->folder_id);

        $this->browse(['folder' => $this->archive->id])
            ->assertActionHidden(TestAction::make('archive')->table($message))
            ->call('undoMove', [$message->id => $this->inbox->id])
            ->assertNotified(__('filament-mailbox::mailbox.actions.undo.success'));

        $this->assertSame($this->inbox->id, $message->refresh()->folder_id);
    }

    public function test_undo_ignores_foreign_messages_and_folders(): void
    {
        $foreign = MailboxMessage::factory()->create();
        $foreignFolder = MailboxFolder::factory()->create();
        $message = MailboxMessage::factory()->for($this->archive, 'folder')->create();

        $this->browse()->call('undoMove', [$foreign->id => $this->inbox->id, $message->id => $foreignFolder->id]);

        $this->assertNotSame($this->inbox->id, $foreign->refresh()->folder_id);
        $this->assertSame($this->archive->id, $message->refresh()->folder_id);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_action_is_disabled_without_archive_folder_or_move_support(): void
    {
        $message = $this->message(1);
        $this->archive->forceFill(['special_use' => null])->save();

        $this->browse()->assertActionDisabled(TestAction::make('archive')->table($message));

        $this->archive->forceFill(['special_use' => SpecialUse::Archive])->save();

        $this->browse()->assertActionEnabled(TestAction::make('archive')->table($message));

        $this->provider->capabilities = [ProviderCapability::Flags];
        app(ProviderCapabilities::class)->flush();

        $this->browse()->assertActionDisabled(TestAction::make('archive')->table($message));
    }

    public function test_bulk_archive(): void
    {
        $messages = collect([1, 2, 3])->map(fn (int $uid) => $this->message($uid));

        $this->browse()
            ->selectTableRecords($messages->take(2))
            ->callAction(TestAction::make('archive')->table()->bulk())
            ->assertNotified(__('filament-mailbox::mailbox.actions.archive.success'));

        $this->assertSame(2, MailboxMessage::where('folder_id', $this->archive->id)->count());

        $this->browse(['folder' => $this->archive->id])
            ->assertActionHidden(TestAction::make('archive')->table()->bulk());
    }

    public function test_view_page_archives_and_redirects(): void
    {
        $message = $this->message(1, ['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->callAction('archive')
            ->assertRedirect(BrowseMailbox::getUrl(['record' => $this->mailbox, 'folder' => $this->inbox->id]));

        $this->assertSame($this->archive->id, $message->refresh()->folder_id);
    }

    public function test_archive_folder_can_be_configured_on_the_mailbox(): void
    {
        $custom = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Erledigt', 'full_name' => 'Erledigt']);
        $foreign = MailboxFolder::factory()->create();

        Livewire::test(EditMailbox::class, ['record' => $this->mailbox->id])
            ->fillForm(['archive_folder_id' => $foreign->id])
            ->call('save')
            ->assertHasFormErrors(['archive_folder_id']);

        Livewire::test(EditMailbox::class, ['record' => $this->mailbox->id])
            ->fillForm(['archive_folder_id' => $custom->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->mailbox->refresh()->archiveFolder()->is($custom));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function browse(array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->test(BrowseMailbox::class, ['record' => $this->mailbox->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function message(int $uid, array $attributes = []): MailboxMessage
    {
        $this->provider->addMessage('INBOX', $uid);

        return MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:'.$uid, ...$attributes]);
    }
}
