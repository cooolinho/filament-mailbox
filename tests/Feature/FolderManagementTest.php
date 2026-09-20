<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\FolderCreated;
use Cooolinho\FilamentMailbox\Events\FolderDeleted;
use Cooolinho\FilamentMailbox\Events\FolderRenamed;
use Cooolinho\FilamentMailbox\Exceptions\InvalidFolderOperation;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ManageFolders;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxFolderJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\FolderManager;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

class FolderManagementTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Trash', 'Trash', '/', SpecialUse::Trash))
            ->addFolder(new FolderData('Projekte', 'Projekte', '/'))
            ->addFolder(new FolderData('Acme', 'Projekte/Acme', '/', parentRemoteId: 'Projekte'))
            ->addFolder(new FolderData('2026', 'Projekte/Acme/2026', '/', parentRemoteId: 'Projekte/Acme'));
        $this->provider->subscribed = ['INBOX', 'Trash', 'Projekte', 'Projekte/Acme'];

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);

        app(SyncService::class)->syncFolders($this->mailbox, $this->provider);
    }

    public function test_sync_mirrors_subscriptions_and_statistics(): void
    {
        $this->provider->addMessage('Projekte', new MessageData('1', textBody: 'abc'));

        $this->assertFalse($this->folder('Projekte/Acme/2026')->is_subscribed);
        $this->assertTrue($this->folder('Projekte/Acme')->is_subscribed);

        app(SyncService::class)->syncFolder($this->folder('Projekte'), $this->provider);

        $projects = $this->folder('Projekte');
        $this->assertSame(1, $projects->message_count);
        $this->assertSame(1, $projects->unseen_count);
        $this->assertSame(3, $projects->size_bytes);
        $this->assertNotNull($projects->statistics_updated_at);
    }

    public function test_create_top_level_and_subfolder(): void
    {
        Event::fake([FolderCreated::class]);

        $manager = app(FolderManager::class);

        $customers = $manager->create($this->mailbox, 'Kunden');
        $child = $manager->create($this->mailbox, 'Entwürfe 2026', $customers);

        $this->assertSame('Kunden', $customers->remote_id);
        $this->assertSame('Kunden/Entw&APw-rfe 2026', $child->full_name);
        $this->assertSame('Entwürfe 2026', $child->name);
        $this->assertSame($customers->id, $child->parent_id);
        $this->assertArrayHasKey('Kunden/Entw&APw-rfe 2026', $this->provider->folders);
        Event::assertDispatchedTimes(FolderCreated::class, 2);

        // The next sync finds the same folders: no duplicates, nothing deactivated.
        app(SyncService::class)->syncFolders($this->mailbox, $this->provider);

        $this->assertSame(7, $this->mailbox->folders()->count());
        $this->assertSame(7, $this->mailbox->folders()->where('is_active', true)->count());
    }

    public function test_names_are_validated_before_reaching_the_server(): void
    {
        $manager = app(FolderManager::class);

        foreach (['', 'a/b', 'x*', 'projekte', 'inbox'] as $name) {
            try {
                $manager->create($this->mailbox, $name);
                $this->fail("Name [{$name}] was accepted.");
            } catch (InvalidFolderOperation) {
            }
        }

        $this->assertSame([], $this->provider->calls);

        // The same name below another parent is fine.
        $manager->create($this->mailbox, 'Acme');
        $this->assertTrue($this->mailbox->folders()->where('full_name', 'Acme')->exists());
    }

    public function test_rename_updates_the_folder_and_its_descendants(): void
    {
        Event::fake([FolderRenamed::class]);

        $projects = $this->folder('Projekte');
        $message = MailboxMessage::factory()->for($this->folder('Projekte/Acme'), 'folder')->create();
        $ids = $this->mailbox->folders()->pluck('id', 'full_name');

        app(FolderManager::class)->rename($projects, 'Kunden');

        $this->assertSame('Kunden', $projects->refresh()->full_name);
        $this->assertSame($ids['Projekte/Acme'], $this->folder('Kunden/Acme')->id);
        $this->assertSame('Kunden/Acme/2026', $this->folder('Kunden/Acme/2026')->remote_id);
        $this->assertSame($ids['Projekte/Acme'], $message->refresh()->folder_id);
        $this->assertSame(['renameFolder', null, ['Projekte', 'Kunden', null]], $this->provider->calls[0]);

        Event::assertDispatched(FolderRenamed::class, fn (FolderRenamed $event): bool => $event->oldFullName === 'Projekte');
        Queue::assertPushed(SyncMailboxFolderJob::class, 3);

        app(SyncService::class)->syncFolders($this->mailbox, $this->provider);

        $this->assertSame(5, $this->mailbox->folders()->where('is_active', true)->count());
        $this->assertSame(5, $this->mailbox->folders()->count());
    }

    public function test_move_below_another_folder_and_back_to_the_top(): void
    {
        $manager = app(FolderManager::class);
        $acme = $this->folder('Projekte/Acme');
        $archive = $manager->create($this->mailbox, 'Archiv');

        $manager->move($acme, $archive);

        $this->assertSame('Archiv/Acme', $acme->refresh()->full_name);
        $this->assertSame($archive->id, $acme->parent_id);
        $this->assertSame('Archiv/Acme/2026', $this->folder('Archiv/Acme/2026')->remote_id);

        $manager->move($acme, null);

        $this->assertSame('Acme', $acme->refresh()->full_name);
        $this->assertNull($acme->parent_id);
    }

    public function test_cycles_and_system_folders_are_rejected(): void
    {
        $manager = app(FolderManager::class);
        $projects = $this->folder('Projekte');

        $this->assertInvalid(fn () => $manager->move($projects, $this->folder('Projekte/Acme/2026')), 'cycle');
        $this->assertInvalid(fn () => $manager->move($projects, $projects), 'cycle');
        $this->assertInvalid(fn () => $manager->rename($this->folder('INBOX'), 'Posteingang'), 'protected');
        $this->assertInvalid(fn () => $manager->delete($this->folder('Trash')), 'protected');
        $this->assertInvalid(fn () => $manager->create($this->mailbox, 'X', MailboxFolder::factory()->create()), 'foreign_folder');

        $this->assertSame([], $this->provider->calls);
        $this->assertSame([], $manager->possibleParents($projects)->whereIn('full_name', ['Projekte/Acme', 'Projekte/Acme/2026'])->all());
    }

    public function test_delete_moves_messages_to_the_trash_deepest_folder_first(): void
    {
        Event::fake([FolderDeleted::class]);

        $this->provider->addMessage('Projekte/Acme/2026', 1);
        $message = MailboxMessage::factory()->for($this->folder('Projekte/Acme/2026'), 'folder')->create(['remote_id' => '1:1']);

        app(FolderManager::class)->delete($this->folder('Projekte'));

        $this->assertSame(
            ['moveMessage', 'deleteFolder', 'deleteFolder', 'deleteFolder'],
            array_column($this->provider->calls, 0),
        );
        $this->assertSame(['Projekte/Acme/2026', 'Projekte/Acme', 'Projekte'], array_column(array_slice($this->provider->calls, 1), 2));
        $this->assertSame($this->folder('Trash')->id, $message->refresh()->folder_id);
        $this->assertSame(0, $this->mailbox->folders()->where('is_active', true)->where('full_name', 'like', 'Projekte%')->count());
        Event::assertDispatched(FolderDeleted::class);
    }

    public function test_permanent_delete_requires_configuration(): void
    {
        $folder = $this->folder('Projekte/Acme/2026');
        $message = MailboxMessage::factory()->for($folder, 'folder')->create();

        $this->assertInvalid(fn () => app(FolderManager::class)->delete($folder, FolderManager::DELETE_PERMANENTLY), 'permanent_delete_disabled');

        config(['filament-mailbox.folders.allow_permanent_delete' => true]);
        app(FolderManager::class)->delete($folder, FolderManager::DELETE_PERMANENTLY);

        $this->assertSoftDeleted($message);
        $this->assertFalse($folder->refresh()->is_active);
    }

    public function test_a_recreated_folder_reuses_the_deactivated_record(): void
    {
        $manager = app(FolderManager::class);
        $folder = $this->folder('Projekte/Acme/2026');

        $manager->delete($folder);
        $recreated = $manager->create($this->mailbox, '2026', $this->folder('Projekte/Acme'));

        $this->assertSame($folder->id, $recreated->id);
        $this->assertTrue($recreated->is_active);
    }

    public function test_subscriptions_can_be_toggled(): void
    {
        $folder = $this->folder('Projekte/Acme/2026');

        app(FolderManager::class)->subscribe($folder, true);

        $this->assertTrue($folder->refresh()->is_subscribed);
        $this->assertContains('Projekte/Acme/2026', $this->provider->subscribed);
    }

    public function test_folder_operations_wait_for_a_running_sync(): void
    {
        config(['filament-mailbox.folders.lock_seconds' => 1]);
        $lock = Cache::lock("filament-mailbox-folders:{$this->mailbox->id}", 10);
        $lock->get();

        try {
            app(FolderManager::class)->create($this->mailbox, 'Blocked');
            $this->fail('The lock was ignored.');
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->provider->calls);
    }

    public function test_manage_folders_page(): void
    {
        $page = Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])
            ->assertOk()
            ->assertCanSeeTableRecords([$this->folder('Projekte'), $this->folder('Projekte/Acme/2026')])
            ->assertTableColumnFormattedStateSet('name', '— — 2026', $this->folder('Projekte/Acme/2026'))
            ->assertActionDisabled(TestAction::make('renameFolder')->table($this->folder('INBOX')))
            ->assertActionEnabled(TestAction::make('renameFolder')->table($this->folder('Projekte')));

        $page->callAction(TestAction::make('createFolder')->table(), ['name' => 'a/b'])
            ->assertHasFormErrors(['name']);

        Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('createFolder')->table(), ['name' => 'Kunden', 'parent' => $this->folder('Projekte')->id])
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.folders.actions.created'));

        $this->assertTrue($this->mailbox->folders()->where('full_name', 'Projekte/Kunden')->exists());

        Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('renameFolder')->table($this->folder('Projekte/Kunden')), ['name' => 'Acme'])
            ->assertNotified(__('filament-mailbox::mailbox.folders.errors.name_taken', ['name' => 'Acme']));

        Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('deleteFolder')->table($this->folder('Projekte/Kunden')), ['mode' => 'trash', 'confirmation' => 'wrong'])
            ->assertHasFormErrors(['confirmation']);

        Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('deleteFolder')->table($this->folder('Projekte/Kunden')), ['mode' => 'trash', 'confirmation' => 'Kunden'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.folders.actions.deleted'));

        $this->assertFalse($this->mailbox->folders()->where('full_name', 'Projekte/Kunden')->value('is_active'));
    }

    public function test_manage_folders_requires_the_ability(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])->assertActionHidden('manageFolders');
        Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])->assertForbidden();

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE_FOLDERS, fn () => true);

        Livewire::test(ManageFolders::class, ['record' => $this->mailbox->id])->assertOk();
        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])->assertActionVisible('manageFolders');
    }

    public function test_messages_can_be_moved_to_any_folder_with_undo(): void
    {
        $inbox = $this->folder('INBOX');
        $target = $this->folder('Projekte/Acme');
        $this->provider->addMessage('INBOX', 1)->addMessage('INBOX', 2)->addMessage('INBOX', 3);
        $messages = collect([1, 2, 3])->map(fn (int $uid) => MailboxMessage::factory()->for($inbox, 'folder')->create(['remote_id' => '1:'.$uid, 'is_read' => true]));

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('moveTo')->table($messages[0]), ['folder' => MailboxFolder::factory()->create()->id])
            ->assertHasFormErrors(['folder']);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->selectTableRecords($messages->take(2))
            ->callAction(TestAction::make('moveTo')->table()->bulk(), ['folder' => $target->id])
            ->assertNotified(__('filament-mailbox::mailbox.actions.move.success'));

        $this->assertSame(2, $target->messages()->count());

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->call('undoMove', [$messages[0]->id => $inbox->id]);

        $this->assertSame($inbox->id, $messages[0]->refresh()->folder_id);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $messages[2]->id])
            ->callAction('moveTo', ['folder' => $target->id])
            ->assertRedirect();

        $this->assertSame($target->id, $messages[2]->refresh()->folder_id);
    }

    protected function folder(string $fullName): MailboxFolder
    {
        return $this->mailbox->folders()->where('full_name', $fullName)->firstOrFail();
    }

    protected function assertInvalid(callable $operation, string $reason): void
    {
        try {
            $operation();
        } catch (InvalidFolderOperation $exception) {
            $this->assertSame(__("filament-mailbox::mailbox.folders.errors.{$reason}", ['name' => '', 'delimiter' => '']), $exception->getMessage());

            return;
        }

        $this->fail("Expected the operation to be rejected ({$reason}).");
    }
}
