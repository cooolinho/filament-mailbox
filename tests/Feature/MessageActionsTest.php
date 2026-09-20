<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

class MessageActionsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $trash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->trash = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Trash)->create(['name' => 'Papierkorb', 'full_name' => 'Papierkorb']);
    }

    public function test_mark_as_read_and_unread_row_actions(): void
    {
        $message = $this->message(['is_read' => false]);

        $this->browse()
            ->callAction(TestAction::make('markAsRead')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.mark_read.success'));

        $this->assertTrue($message->refresh()->is_read);

        $this->browse()
            ->callAction(TestAction::make('markAsUnread')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.mark_unread.success'));

        $this->assertFalse($message->refresh()->is_read);
        $this->assertSame(['markRead', 'markUnread'], array_column($this->provider->calls, 0));
    }

    public function test_delete_moves_message_to_trash(): void
    {
        $message = $this->message();

        $this->browse()
            ->callAction(TestAction::make('deleteMessage')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.delete.success'))
            ->assertCanNotSeeTableRecords([$message]);

        $this->assertSoftDeleted($message);
        [$operation, $identifier, $trash] = $this->provider->calls[0];
        $this->assertSame('delete', $operation);
        $this->assertSame($message->remote_id, $identifier->remoteId);
        $this->assertSame('Papierkorb', $trash->remoteId);
    }

    public function test_delete_in_trash_deletes_permanently(): void
    {
        $message = MailboxMessage::factory()->for($this->trash, 'folder')->create();

        $this->browse(['folder' => $this->trash->id])
            ->callAction(TestAction::make('deleteMessage')->table($message));

        $this->assertSoftDeleted($message);
        $this->assertNull($this->provider->calls[0][2]);
    }

    public function test_bulk_actions(): void
    {
        $messages = MailboxMessage::factory()->count(3)->for($this->inbox, 'folder')->create(['is_read' => false]);

        $this->browse()
            ->selectTableRecords($messages)
            ->callAction(TestAction::make('markAsRead')->table()->bulk())
            ->assertNotified();

        $this->assertSame(3, MailboxMessage::where('is_read', true)->count());

        $this->browse()
            ->selectTableRecords($messages)
            ->callAction(TestAction::make('markAsUnread')->table()->bulk());

        $this->assertSame(3, MailboxMessage::where('is_read', false)->count());

        $this->browse()
            ->selectTableRecords($messages->take(2))
            ->callAction(TestAction::make('deleteMessages')->table()->bulk());

        $this->assertSame(1, MailboxMessage::count());
        $this->assertSame(3 + 3 + 2, count($this->provider->calls));
    }

    public function test_remote_failure_keeps_local_state_and_notifies(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function markRead(MessageIdentifier $message): void
            {
                throw new RuntimeException('server gone');
            }
        };
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($failing));

        $message = $this->message(['is_read' => false]);

        $this->browse()
            ->callAction(TestAction::make('markAsRead')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.failed'));

        $this->assertFalse($message->refresh()->is_read);
        $this->assertTrue($failing->disconnected);
    }

    public function test_view_page_actions(): void
    {
        $message = $this->message(['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->callAction('markAsUnread')
            ->assertRedirect();

        $this->assertFalse($message->refresh()->is_read);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->callAction('deleteMessage')
            ->assertRedirect();

        $this->assertSoftDeleted($message);
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
    protected function message(array $attributes = []): MailboxMessage
    {
        return MailboxMessage::factory()->for($this->inbox, 'folder')->create($attributes);
    }
}
