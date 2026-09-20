<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Navigation\NavigationItem;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;

class StarredTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $projects;

    protected MailboxFolder $trash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Projects', 'Projects', '/'))
            ->addFolder(new FolderData('Trash', 'Trash', '/', SpecialUse::Trash));

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->projects = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Projects', 'full_name' => 'Projects']);
        $this->trash = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Trash)->create(['name' => 'Trash', 'full_name' => 'Trash']);
    }

    public function test_star_column_toggles_the_flag_on_the_server(): void
    {
        $message = $this->message($this->inbox, 1);

        $this->browse()
            ->callAction(TestAction::make('toggleStar')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.star.success'));

        $this->assertTrue($message->refresh()->is_flagged);
        $this->assertTrue($this->provider->messages['INBOX'][1]->flags->flagged);

        $this->browse()
            ->callAction(TestAction::make('toggleStar')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.unstar.success'));

        $this->assertFalse($message->refresh()->is_flagged);
        $this->assertFalse($this->provider->messages['INBOX'][1]->flags->flagged);
    }

    public function test_bulk_star_and_unstar(): void
    {
        $messages = collect([1, 2, 3])->map(fn (int $uid) => $this->message($this->inbox, $uid));

        $this->browse()
            ->selectTableRecords($messages)
            ->callAction(TestAction::make('star')->table()->bulk())
            ->assertNotified(__('filament-mailbox::mailbox.actions.star.success'));

        $this->assertSame(3, MailboxMessage::where('is_flagged', true)->count());

        $this->browse()
            ->selectTableRecords($messages->take(2))
            ->callAction(TestAction::make('unstar')->table()->bulk());

        $this->assertSame(1, MailboxMessage::where('is_flagged', true)->count());
    }

    public function test_view_page_toggles_the_star(): void
    {
        $message = $this->message($this->inbox, 1, ['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertActionHasLabel('toggleStar', __('filament-mailbox::mailbox.actions.star.label'))
            ->callAction('toggleStar')
            ->assertNotified(__('filament-mailbox::mailbox.actions.star.success'));

        $this->assertTrue($message->refresh()->is_flagged);
    }

    public function test_failure_keeps_the_local_state(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
            {
                throw new RuntimeException('server gone');
            }
        };
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($failing));

        $message = $this->message($this->inbox, 1);

        $this->browse()
            ->callAction(TestAction::make('toggleStar')->table($message))
            ->assertNotified(__('filament-mailbox::mailbox.actions.failed'));

        $this->assertFalse($message->refresh()->is_flagged);
    }

    public function test_without_flag_capability_only_the_local_copy_changes(): void
    {
        $this->provider->capabilities = [];
        $message = $this->message($this->inbox, 1);

        app(MessageService::class)->setFlagged($message, true);

        $this->assertTrue($message->refresh()->is_flagged);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_flags_changed_on_the_server_are_synchronised(): void
    {
        $this->provider->addMessage('INBOX', new MessageData('1', subject: 'Starred elsewhere'));

        $sync = app(SyncService::class);
        $sync->syncFolder($this->inbox, $this->provider);

        $message = MailboxMessage::sole();
        $this->assertFalse($message->is_flagged);

        $this->provider->messages['INBOX'][1] = $this->provider->messages['INBOX'][1]->with(['flags' => new MessageFlags(flagged: true)]);
        $sync->syncFolder($this->inbox->refresh(), $this->provider);

        $this->assertTrue($message->refresh()->is_flagged);
    }

    public function test_starred_view_lists_starred_messages_of_all_folders_except_trash(): void
    {
        $inbox = $this->message($this->inbox, 1, ['is_flagged' => true]);
        $project = $this->message($this->projects, 1, ['is_flagged' => true]);
        $unstarred = $this->message($this->inbox, 2);
        $trashed = $this->message($this->trash, 1, ['is_flagged' => true]);
        $foreign = MailboxMessage::factory()->create(['is_flagged' => true]);

        $this->browse(['view' => 'starred'])
            ->assertCanSeeTableRecords([$inbox, $project])
            ->assertCanNotSeeTableRecords([$unstarred, $trashed, $foreign])
            ->assertTableColumnVisible('folder.name')
            ->assertSee(__('filament-mailbox::mailbox.starred.label'));
    }

    public function test_starred_navigation_item_follows_the_inbox_with_a_count(): void
    {
        $this->message($this->inbox, 1, ['is_flagged' => true]);
        $this->message($this->projects, 1, ['is_flagged' => true]);
        $this->message($this->trash, 1, ['is_flagged' => true]);

        $page = $this->browse(['view' => 'starred'])->instance();
        $items = collect(array_values($page->getCachedSubNavigation())[0]->getItems());

        $this->assertSame(['Inbox', 'Starred', 'Snoozed', 'Trash'], $items->map(fn (NavigationItem $item) => $item->getLabel())->all());
        $starred = $items->first(fn (NavigationItem $item) => $item->getLabel() === 'Starred');
        $this->assertSame('2', $starred->getBadge());
        $this->assertTrue($starred->isActive());
        $this->assertFalse($items->first()->isActive());
    }

    public function test_starred_navigation_can_be_disabled(): void
    {
        config(['filament-mailbox.starred.navigation' => false]);

        $page = $this->browse(['view' => 'starred'])->instance();
        $items = collect(array_values($page->getCachedSubNavigation())[0]->getItems());

        $this->assertNotContains('Starred', $items->map(fn (NavigationItem $item) => $item->getLabel())->all());
        $this->assertFalse($page->isStarredView());
    }

    public function test_filter_only_starred(): void
    {
        $starred = $this->message($this->inbox, 1, ['is_flagged' => true]);
        $other = $this->message($this->inbox, 2);

        $this->browse()
            ->filterTable('is_flagged', true)
            ->assertCanSeeTableRecords([$starred])
            ->assertCanNotSeeTableRecords([$other]);
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
    protected function message(MailboxFolder $folder, int $uid, array $attributes = []): MailboxMessage
    {
        $this->provider->addMessage($folder->remote_id, new MessageData((string) $uid, flags: new MessageFlags(flagged: $attributes['is_flagged'] ?? false)));

        return MailboxMessage::factory()->for($folder, 'folder')->create(['remote_id' => '1:'.$uid, ...$attributes]);
    }
}
