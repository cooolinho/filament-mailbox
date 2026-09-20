<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Livewire\MessagePreview;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\UserPreferences;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class SplitPaneTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create(['sync_cursor' => ['uid_validity' => 7, 'last_uid' => 100]]);
        MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Trash)->create(['name' => 'Trash', 'full_name' => 'Trash']);
    }

    protected function message(array $attributes = []): MailboxMessage
    {
        static $uid = 0;
        $uid++;

        return MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'remote_id' => '7:'.$uid,
            ...$attributes,
        ]);
    }

    protected function split(): void
    {
        app(UserPreferences::class)->set($this->user, 'layout', BrowseMailbox::LAYOUT_SPLIT);
    }

    public function test_the_list_view_is_the_default_and_rows_open_the_message_page(): void
    {
        $message = $this->message();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertSet('mailboxLayout', BrowseMailbox::LAYOUT_LIST)
            ->assertDontSeeLivewire(MessagePreview::class)
            ->assertSeeHtml(e(MailboxResource::getUrl('message', ['record' => $this->mailbox, 'message' => $message])));
    }

    public function test_the_layout_is_switched_and_stored_per_user(): void
    {
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->callAction('layoutSplit')
            ->assertRedirect(BrowseMailbox::getUrl(['record' => $this->mailbox, 'folder' => $this->inbox->id]));

        $this->assertSame('split', app(UserPreferences::class)->get($this->user, 'layout'));
        $this->assertSame(['layout' => 'split'], json_decode(DB::table('mailbox_user_preferences')->where('user_id', $this->user->id)->value('preferences'), true));

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertSet('mailboxLayout', BrowseMailbox::LAYOUT_SPLIT)
            ->assertSeeLivewire(MessagePreview::class)
            ->callAction('layoutList');

        $this->assertSame('list', (new UserPreferences)->get($this->user, 'layout'));

        // Other users keep the configured default.
        $this->assertNull(app(UserPreferences::class)->get(User::make('Other'), 'layout'));
    }

    public function test_the_split_view_can_be_disabled_and_has_a_configurable_default(): void
    {
        config(['filament-mailbox.ui.split_pane.default' => 'split']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertSet('mailboxLayout', BrowseMailbox::LAYOUT_SPLIT);

        config(['filament-mailbox.ui.split_pane.enabled' => false]);
        $this->split();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertSet('mailboxLayout', BrowseMailbox::LAYOUT_LIST)
            ->assertActionHidden('layoutSplit');
    }

    public function test_clicking_a_row_previews_the_message_and_marks_it_as_read(): void
    {
        $this->split();
        $message = $this->message(['subject' => 'Quarterly report']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertDontSeeHtml(e(MailboxResource::getUrl('message', ['record' => $this->mailbox, 'message' => $message])))
            ->call('preview', $message->id)
            ->assertSet('message', $message->id)
            ->assertSet('messageIndex', 0);

        $this->assertTrue($message->refresh()->is_read);
        $this->assertSame('markRead', $this->provider->calls[0][0]);

        Livewire::test(MessagePreview::class, ['mailboxId' => $this->mailbox->id, 'messageId' => $message->id])
            ->assertSee('Quarterly report')
            ->assertSee($message->from_address)
            ->assertActionVisible('reply')
            ->assertSee(__('filament-mailbox::mailbox.actions.reply.label'))
            ->assertSee(__('filament-mailbox::mailbox.split.open_full'));
    }

    public function test_a_deep_link_restores_the_selection(): void
    {
        $this->split();
        $message = $this->message();

        Livewire::withQueryParams(['message' => $message->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertSet('message', $message->id);

        $this->assertTrue($message->refresh()->is_read);
    }

    public function test_foreign_messages_are_never_previewed(): void
    {
        $this->split();
        $foreign = MailboxMessage::factory()->create(['subject' => 'Secret foreign']);

        Livewire::withQueryParams(['message' => $foreign->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertSet('message', null)
            ->call('preview', $foreign->id)
            ->assertSet('message', null);

        Livewire::test(MessagePreview::class, ['mailboxId' => $this->mailbox->id, 'messageId' => $foreign->id])
            ->assertDontSee('Secret foreign')
            ->assertSee(__('filament-mailbox::mailbox.split.empty.heading'));

        // A mailbox the user is not assigned to.
        $other = Mailbox::factory()->create();
        $otherMessage = MailboxMessage::factory()->for(MailboxFolder::factory()->for($other)->inbox(), 'folder')->create(['subject' => 'Not assigned']);

        Livewire::test(MessagePreview::class, ['mailboxId' => $other->id, 'messageId' => $otherMessage->id])
            ->assertDontSee('Not assigned');
        $this->assertFalse($foreign->refresh()->is_read);
    }

    public function test_deleting_in_the_preview_selects_the_next_message(): void
    {
        $this->split();
        $newest = $this->message(['received_at' => now()]);
        $middle = $this->message(['received_at' => now()->subHour()]);
        $oldest = $this->message(['received_at' => now()->subHours(2)]);

        $page = Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->call('preview', $middle->id)
            ->assertSet('messageIndex', 1);

        Livewire::test(MessagePreview::class, ['mailboxId' => $this->mailbox->id, 'messageId' => $middle->id])
            ->callAction('deleteMessage')
            ->assertDispatched(MessagePreview::REMOVED_EVENT, id: $middle->id);

        $this->assertFalse(MailboxMessage::query()->where('folder_id', $this->inbox->id)->whereKey($middle->id)->exists());

        $page->dispatch(MessagePreview::REMOVED_EVENT, id: $middle->id)
            ->assertSet('message', $oldest->id)
            ->assertSet('messageIndex', 1);

        $this->assertTrue($oldest->refresh()->is_read);

        // Removing the last message of the list selects the new last one.
        $oldest->delete();

        $page->dispatch(MessagePreview::REMOVED_EVENT, id: $oldest->id)
            ->assertSet('message', $newest->id);

        // Events for other messages are ignored.
        $page->dispatch(MessagePreview::REMOVED_EVENT, id: 999)
            ->assertSet('message', $newest->id);
    }

    public function test_marking_as_unread_in_the_preview_closes_it(): void
    {
        $this->split();
        $message = $this->message(['is_read' => true]);

        Livewire::test(MessagePreview::class, ['mailboxId' => $this->mailbox->id, 'messageId' => $message->id])
            ->callAction('markAsUnread')
            ->assertDispatched(MessagePreview::CLOSED_EVENT);

        $this->assertFalse($message->refresh()->is_read);

        Livewire::withQueryParams(['message' => $message->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->dispatch(MessagePreview::CLOSED_EVENT)
            ->assertSet('message', null);
    }

    public function test_star_action_in_the_preview_updates_the_list(): void
    {
        $message = $this->message(['is_read' => true]);

        Livewire::test(MessagePreview::class, ['mailboxId' => $this->mailbox->id, 'messageId' => $message->id])
            ->callAction('toggleStar')
            ->assertDispatched(MessagePreview::UPDATED_EVENT);

        $this->assertTrue($message->refresh()->is_flagged);
    }
}
