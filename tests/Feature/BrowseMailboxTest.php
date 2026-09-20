<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\FolderNavigation;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

class BrowseMailboxTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $sent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);

        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->sent = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Sent)->create(['name' => 'Gesendet', 'full_name' => 'Gesendet']);
    }

    public function test_it_opens_the_inbox_by_default(): void
    {
        $inboxMessages = MailboxMessage::factory()->count(3)->for($this->inbox, 'folder')->create();
        $sentMessage = MailboxMessage::factory()->for($this->sent, 'folder')->create();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertOk()
            ->assertSet('folder', $this->inbox->id)
            ->assertCanSeeTableRecords($inboxMessages)
            ->assertCanNotSeeTableRecords([$sentMessage]);
    }

    public function test_it_switches_folders_from_local_data_only(): void
    {
        $provider = $this->fakeProvider();
        $inboxMessage = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        $sentMessage = MailboxMessage::factory()->for($this->sent, 'folder')->create();

        Livewire::withQueryParams(['folder' => $this->sent->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertOk()
            ->assertCanSeeTableRecords([$sentMessage])
            ->assertCanNotSeeTableRecords([$inboxMessage]);

        // A provider may be created to resolve capabilities, but it is never contacted.
        $this->assertLessThanOrEqual(1, count($this->app->make(MailboxProviderFactory::class)->made));
        $this->assertSame([], $provider->calls);
        $this->assertFalse($provider->disconnected);
    }

    public function test_folder_of_another_mailbox_is_ignored(): void
    {
        $foreignFolder = MailboxFolder::factory()->inbox()->create();
        $foreignMessage = MailboxMessage::factory()->for($foreignFolder, 'folder')->create();

        Livewire::withQueryParams(['folder' => $foreignFolder->id])
            ->test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertOk()
            ->assertSet('folder', $this->inbox->id)
            ->assertCanNotSeeTableRecords([$foreignMessage]);
    }

    public function test_messages_are_sorted_newest_first(): void
    {
        $old = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['received_at' => now()->subDay()]);
        $new = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['received_at' => now()]);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    }

    public function test_sub_navigation_lists_system_and_custom_folders(): void
    {
        $customers = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Customers', 'full_name' => 'Customers']);
        MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Acme', 'full_name' => 'Customers/Acme', 'parent_id' => $customers->id]);
        MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Old', 'full_name' => 'Old', 'is_active' => false]);
        MailboxMessage::factory()->count(2)->for($this->inbox, 'folder')->create();

        $page = Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])->instance();
        $groups = array_values($page->getCachedSubNavigation());

        $system = collect($groups[0]->getItems());
        $this->assertSame(['Inbox', 'Starred', 'Snoozed', 'Sent'], $system->map(fn (NavigationItem $item) => $item->getLabel())->all());
        $this->assertSame('2', $system->first()->getBadge());
        $this->assertTrue($system->first()->isActive());

        $this->assertInstanceOf(NavigationGroup::class, $groups[1]);
        $custom = collect($groups[1]->getItems());
        $this->assertSame(['Customers'], $custom->map(fn (NavigationItem $item) => $item->getLabel())->all());
        $this->assertSame(['Acme'], collect($custom->first()->getChildItems())->map(fn (NavigationItem $item) => $item->getLabel())->all());
    }

    public function test_nested_custom_folders_below_system_folders_are_top_level(): void
    {
        $projects = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'Projects', 'full_name' => 'INBOX/Projects', 'parent_id' => $this->inbox->id]);
        $a = MailboxFolder::factory()->for($this->mailbox)->create(['name' => 'A', 'full_name' => 'INBOX/Projects/A', 'parent_id' => $projects->id]);
        MailboxFolder::factory()->for($this->mailbox)->create(['name' => '2026', 'full_name' => 'INBOX/Projects/A/2026', 'parent_id' => $a->id]);

        $navigation = new FolderNavigation;
        $items = collect($navigation->customFolders($navigation->folders($this->mailbox)))
            ->mapWithKeys(fn (array $item) => [$item['folder']->full_name => [$item['root']?->full_name, $item['label']]]);

        $this->assertSame([null, 'Projects'], $items['INBOX/Projects']);
        $this->assertSame(['INBOX/Projects', 'A'], $items['INBOX/Projects/A']);
        $this->assertSame(['INBOX/Projects', 'A / 2026'], $items['INBOX/Projects/A/2026']);
    }

    public function test_sync_action_dispatches_job_and_notifies(): void
    {
        Queue::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->getRouteKey()])
            ->callAction('sync')
            ->assertNotified(__('filament-mailbox::mailbox.actions.sync.started'));

        Queue::assertPushed(SyncMailboxJob::class, fn (SyncMailboxJob $job) => $job->mailbox->is($this->mailbox));
    }

    public function test_browse_url_is_generated(): void
    {
        $this->assertStringEndsWith(
            "/admin/mailboxes/{$this->mailbox->id}?folder={$this->sent->id}",
            MailboxResource::getUrl('browse', ['record' => $this->mailbox, 'folder' => $this->sent->id]),
        );
    }
}
