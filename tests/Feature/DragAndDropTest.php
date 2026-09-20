<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

class DragAndDropTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $projects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Projects', 'Projects', '/'));
        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create([]);
        $this->projects = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Projects', 'full_name' => 'Projects', 'remote_id' => 'Projects']);
    }

    protected function message(array $attributes = []): MailboxMessage
    {
        static $uid = 0;
        $uid++;

        $this->provider->addMessage('INBOX', $uid);

        return MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:'.$uid, ...$attributes]);
    }

    protected function page()
    {
        return Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()]);
    }

    public function test_dropping_messages_on_a_folder_moves_them_with_undo(): void
    {
        $first = $this->message();
        $second = $this->message();
        $untouched = $this->message();

        $this->page()
            ->call('moveMessages', $this->projects->id, [$first->id, (string) $second->id])
            ->assertNotified(__('filament-mailbox::mailbox.actions.move.success'))
            ->assertCanNotSeeTableRecords([$first, $second])
            ->assertCanSeeTableRecords([$untouched]);

        $this->assertSame($this->projects->id, $first->refresh()->folder_id);
        $this->assertSame($this->projects->id, $second->refresh()->folder_id);
        $this->assertSame($this->inbox->id, $untouched->refresh()->folder_id);
        $this->assertSame('moveMessage', $this->provider->calls[0][0]);

        // Undo moves them back.
        $this->page()->dispatch(BrowseMailbox::UNDO_MOVE_EVENT, moves: [$first->id => $this->inbox->id, $second->id => $this->inbox->id]);

        $this->assertSame($this->inbox->id, $first->refresh()->folder_id);
    }

    public function test_foreign_folders_and_messages_are_ignored(): void
    {
        $message = $this->message();
        $foreignFolder = MailboxFolder::factory()->create();
        $foreignMessage = MailboxMessage::factory()->create();
        $inactive = MailboxFolder::factory()->for($this->mailbox)->create(['is_active' => false]);

        $this->page()
            ->call('moveMessages', $foreignFolder->id, [$message->id])
            ->call('moveMessages', $inactive->id, [$message->id])
            ->call('moveMessages', 'abc', [$message->id])
            ->call('moveMessages', $this->projects->id, [$foreignMessage->id, 'x', ['nested'], -1])
            ->assertNotNotified();

        $this->assertSame($this->inbox->id, $message->refresh()->folder_id);
        $this->assertNotSame($this->projects->id, $foreignMessage->refresh()->folder_id);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_the_batch_is_limited_and_every_message_is_authorised(): void
    {
        config(['filament-mailbox.ui.max_move_batch' => 2]);
        $messages = collect(range(1, 3))->map(fn () => $this->message());

        $this->page()->call('moveMessages', $this->projects->id, $messages->pluck('id')->all());

        $this->assertSame(2, MailboxMessage::query()->where('folder_id', $this->projects->id)->count());

        // Unauthorised messages are skipped.
        Gate::policy(MailboxMessage::class, DenyMovePolicy::class);
        $other = $this->message();

        $this->page()->call('moveMessages', $this->projects->id, [$other->id]);

        $this->assertSame($this->inbox->id, $other->refresh()->folder_id);
    }

    public function test_moves_are_rate_limited(): void
    {
        $message = $this->message();
        RateLimiter::increment('filament-mailbox-move:'.$this->user->id, amount: 60);

        $this->page()
            ->call('moveMessages', $this->projects->id, [$message->id])
            ->assertNotified(__('filament-mailbox::mailbox.drag_and_drop.too_many'));

        $this->assertSame($this->inbox->id, $message->refresh()->folder_id);
    }

    public function test_rows_and_folders_are_marked_for_dragging(): void
    {
        $message = $this->message();

        $page = $this->page()
            ->assertSeeHtml('fi-mailbox-draggable fi-mailbox-message-'.$message->id)
            ->assertSeeHtml('data-filament-mailbox-dnd');

        $items = collect($page->instance()->getSubNavigation())
            ->flatMap(fn ($item) => $item instanceof NavigationGroup ? $item->getItems() : [$item])
            ->filter(fn (NavigationItem $item): bool => str_starts_with((string) $item->getKey(), 'folder-'))
            ->mapWithKeys(fn (NavigationItem $item): array => [$item->getKey() => $item->getExtraAttributes()]);

        $this->assertSame(['data-filament-mailbox-folder' => $this->projects->id], $items['folder-'.$this->projects->id]);
    }

    public function test_drag_and_drop_can_be_disabled(): void
    {
        config(['filament-mailbox.ui.drag_and_drop' => false]);
        $message = $this->message();

        $this->page()
            ->assertDontSeeHtml('fi-mailbox-draggable')
            ->assertDontSeeHtml('data-filament-mailbox-dnd')
            ->call('moveMessages', $this->projects->id, [$message->id]);

        $this->assertSame($this->inbox->id, $message->refresh()->folder_id);
    }

    public function test_the_move_action_is_the_keyboard_alternative(): void
    {
        $message = $this->message();
        $bulk = $this->message();

        $this->page()
            ->callAction(TestAction::make('moveTo')->table($message), ['folder' => $this->projects->id])
            ->assertNotified(__('filament-mailbox::mailbox.actions.move.success'));

        $this->page()
            ->selectTableRecords([$bulk->id])
            ->callAction(TestAction::make('moveTo')->table()->bulk(), ['folder' => $this->projects->id]);

        $this->assertSame($this->projects->id, $message->refresh()->folder_id);
        $this->assertSame($this->projects->id, $bulk->refresh()->folder_id);
    }
}

class DenyMovePolicy extends \Cooolinho\FilamentMailbox\Policies\MailboxMessagePolicy
{
    public function update(\Illuminate\Foundation\Auth\User $user, MailboxMessage $message): bool
    {
        return false;
    }
}
