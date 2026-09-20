<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxSettings;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\UserPreferences;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Support\ShortcutRegistry;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Livewire\Livewire;

class KeyboardShortcutsTest extends TestCase
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
            ->addFolder(new FolderData('Archive', 'Archive', '/', SpecialUse::Archive));

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->archive = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Archive)->create(['name' => 'Archive', 'full_name' => 'Archive', 'remote_id' => 'Archive']);
    }

    public function test_script_and_help_are_rendered_with_the_bindings(): void
    {
        $this->message(1);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertSeeHtml('data-filament-mailbox-shortcuts')
            ->assertSeeHtml('fi-mailbox-row')
            ->assertActionVisible('shortcutsHelp')
            ->mountAction('shortcutsHelp')
            ->assertMountedActionModalSee([__('filament-mailbox::mailbox.shortcuts.actions.go_inbox'), 'Shift + I']);

        $map = ShortcutRegistry::map(ShortcutRegistry::LIST_ACTIONS);
        $this->assertSame('archive', $map['e']);
        $this->assertSame('delete', $map['del']);
        $this->assertSame('go_inbox', $map['g i']);
        $this->assertArrayNotHasKey('u', $map);
        $this->assertSame('back', ShortcutRegistry::map(ShortcutRegistry::MESSAGE_ACTIONS)['u']);
    }

    public function test_row_shortcuts_run_the_existing_actions(): void
    {
        $message = $this->message(1);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->call('shortcut', 'star', $message->id)
            ->call('shortcut', 'mark_read', $message->id)
            ->call('shortcut', 'archive', $message->id)
            ->assertNotified(__('filament-mailbox::mailbox.actions.archive.success'));

        $message->refresh();
        $this->assertTrue($message->is_flagged);
        $this->assertTrue($message->is_read);
        $this->assertSame($this->archive->id, $message->folder_id);
    }

    public function test_delete_opens_the_confirmation(): void
    {
        $message = $this->message(1);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->call('shortcut', 'delete', $message->id)
            ->assertMountedActionModalSee(__('filament-mailbox::mailbox.actions.delete.heading'));

        $this->assertModelExists($message);
    }

    public function test_unknown_actions_foreign_messages_and_forbidden_actions_are_ignored(): void
    {
        $message = $this->message(1);
        $foreign = MailboxMessage::factory()->create();
        app(MailboxAuthorization::class)->using(MailboxAuthorization::DELETE, fn (): bool => false);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->call('shortcut', 'markForwarded', $message->id)
            ->call('shortcut', 'archive', $foreign->id)
            ->call('shortcut', 'archive', 'x')
            ->call('shortcut', 'delete', $message->id)
            ->assertSet('mountedActions', []);

        $this->assertSame($this->inbox->id, $message->refresh()->folder_id);
        $this->assertNotSame($this->archive->id, $foreign->refresh()->folder_id);
    }

    public function test_message_page_renders_the_script_with_the_back_url(): void
    {
        $message = $this->message(1, ['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertSeeHtml('data-page="message"')
            ->assertSeeHtml('data-back-url="'.e(BrowseMailbox::getUrl(['record' => $this->mailbox, 'folder' => $this->inbox->id])).'"')
            ->assertActionVisible('shortcutsHelp');
    }

    public function test_users_can_switch_shortcuts_off(): void
    {
        $message = $this->message(1);

        Livewire::test(MailboxSettings::class)
            ->fillForm(['shortcuts' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(app(UserPreferences::class)->get($this->user, ShortcutRegistry::PREFERENCE));

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertDontSeeHtml('data-filament-mailbox-shortcuts')
            ->assertActionHidden('shortcutsHelp')
            ->call('shortcut', 'archive', $message->id);

        $this->assertSame($this->inbox->id, $message->refresh()->folder_id);
    }

    public function test_shortcuts_can_be_disabled_in_the_config(): void
    {
        config(['filament-mailbox.shortcuts.enabled' => false]);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertDontSeeHtml('data-filament-mailbox-shortcuts');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function message(int $uid, array $attributes = []): MailboxMessage
    {
        $this->provider->addMessage('INBOX', new MessageData((string) $uid));

        return MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:'.$uid, ...$attributes]);
    }
}
