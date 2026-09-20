<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessageTagsChanged;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\TagActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTags\Pages\ListMailboxTags;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Services\TagService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class TagsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxTag $open;

    protected MailboxTag $accounting;

    protected MailboxTag $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox), uidValidity: 100);

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create(['sync_cursor' => null]);

        $this->open = MailboxTag::factory()->create(['name' => 'Offen', 'color' => LabelColor::Amber]);
        $this->accounting = MailboxTag::factory()->for($this->mailbox)->create(['name' => 'Buchhaltung']);
        $this->foreign = MailboxTag::factory()->for(Mailbox::factory())->create(['name' => 'Fremd']);
    }

    public function test_available_tags_are_global_and_mailbox_specific(): void
    {
        $this->assertSame(['Buchhaltung', 'Offen'], app(TagService::class)->availableFor($this->mailbox)->pluck('name')->sort()->values()->all());
    }

    public function test_attach_and_detach(): void
    {
        Event::fake([MessageTagsChanged::class]);

        $messages = MailboxMessage::factory()->count(2)->for($this->inbox, 'folder')->create();
        $service = app(TagService::class);

        $service->attach($messages, $this->open, $this->user);
        $service->attach($messages, $this->open, $this->user);
        $service->attach($messages->first(), $this->accounting);

        $this->assertSame(3, DB::table('mailbox_message_tag')->count());
        $this->assertSame($this->user->id, DB::table('mailbox_message_tag')->where('tag_id', $this->open->id)->value('tagged_by'));
        $this->assertSame($service->messageKey($messages->first()), DB::table('mailbox_message_tag')->where('message_id', $messages->first()->id)->value('message_key'));

        $service->detach($messages, $this->open);

        $this->assertSame(['Buchhaltung'], $messages->first()->tags()->pluck('name')->all());
        Event::assertDispatchedTimes(MessageTagsChanged::class, 4);
    }

    public function test_tags_of_another_mailbox_are_rejected(): void
    {
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create();

        $this->expectException(InvalidArgumentException::class);

        app(TagService::class)->attach($message, $this->foreign);
    }

    public function test_message_key_falls_back_to_sender_date_and_subject(): void
    {
        $service = app(TagService::class);
        $attributes = ['from_address' => 'john@example.com', 'subject' => 'Hi', 'sent_at' => Carbon::parse('2026-09-17 08:00')];

        $a = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['message_id' => '<ID@example.com>', ...$attributes]);
        $b = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['message_id' => 'id@example.com', ...$attributes]);
        $c = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['message_id' => null, ...$attributes]);
        $d = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['message_id' => null, ...$attributes]);

        $this->assertSame($service->messageKey($a), $service->messageKey($b));
        $this->assertSame($service->messageKey($c), $service->messageKey($d));
        $this->assertNotSame($service->messageKey($a), $service->messageKey($c));
    }

    public function test_tags_survive_a_reimport_after_uid_validity_change(): void
    {
        $this->provider->addMessage('INBOX', new MessageData('1', messageId: '<invoice@example.com>', subject: 'Invoice'));
        $sync = app(SyncService::class);
        $sync->syncFolder($this->inbox, $this->provider);

        $original = MailboxMessage::sole();
        app(TagService::class)->attach($original, $this->open, $this->user);

        $this->provider->messages['INBOX'] = [];
        $this->provider->uidValidity['INBOX'] = 101;
        $this->provider->addMessage('INBOX', new MessageData('1', messageId: '<invoice@example.com>', subject: 'Invoice'));
        $sync->syncFolder($this->inbox->refresh(), $this->provider);

        $reimported = MailboxMessage::sole();
        $this->assertNotSame($original->id, $reimported->id);
        $this->assertSame(['Offen'], $reimported->tags()->pluck('name')->all());
        $this->assertSame(0, DB::table('mailbox_message_tag')->where('message_id', $original->id)->count());
    }

    public function test_prune_command_removes_assignments_of_long_deleted_messages(): void
    {
        $old = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        $recent = MailboxMessage::factory()->for($this->inbox, 'folder')->create();
        app(TagService::class)->attach([$old, $recent], $this->open);

        $old->delete();
        $old->forceFill(['deleted_at' => now()->subDays(40)])->save();
        $recent->delete();

        $this->artisan('mailbox:prune-tag-assignments', ['--days' => 30])->assertSuccessful();

        $this->assertSame([$recent->id], DB::table('mailbox_message_tag')->pluck('message_id')->all());
    }

    public function test_row_action_edits_tags_and_badges_are_shown(): void
    {
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create();

        $this->browse()
            ->callAction(TestAction::make('editTags')->table($message), ['tags' => [$this->open->id, $this->accounting->id]])
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.tags.actions.saved'));

        $this->assertEqualsCanonicalizing([$this->open->id, $this->accounting->id], $message->tags()->pluck('mailbox_tags.id')->all());

        $this->browse()
            ->assertTableColumnStateSet('tags', ['Buchhaltung', 'Offen'], $message);
    }

    public function test_foreign_tags_cannot_be_assigned(): void
    {
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create();

        $this->browse()
            ->callAction(TestAction::make('editTags')->table($message), ['tags' => [$this->foreign->id]])
            ->assertHasFormErrors(['tags.0']);

        $this->assertSame([$this->open->id], TagActions::available($this->mailbox, [$this->foreign->id, $this->open->id])->modelKeys());
        $this->assertSame(0, $message->tags()->count());
    }

    public function test_bulk_attach_detach_and_filter(): void
    {
        $messages = MailboxMessage::factory()->count(3)->for($this->inbox, 'folder')->create();

        $this->browse()
            ->selectTableRecords($messages->take(2))
            ->callAction(TestAction::make('attachTag')->table()->bulk(), ['tag' => $this->accounting->id])
            ->assertNotified(__('filament-mailbox::mailbox.tags.actions.saved'));

        $this->browse()
            ->filterTable('tags', [$this->accounting->id])
            ->assertCanSeeTableRecords($messages->take(2))
            ->assertCanNotSeeTableRecords([$messages->last()]);

        $this->browse()
            ->selectTableRecords($messages->take(1))
            ->callAction(TestAction::make('detachTag')->table()->bulk(), ['tag' => $this->accounting->id]);

        $this->assertSame(1, $this->accounting->messages()->count());
    }

    public function test_view_page_shows_and_edits_tags(): void
    {
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['is_read' => true]);
        app(TagService::class)->attach($message, $this->open);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertSee('Offen')
            ->mountAction('editTags')
            ->assertSchemaStateSet(['tags' => [$this->open->id]])
            ->fillForm(['tags' => [$this->accounting->id]])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame(['Buchhaltung'], $message->tags()->pluck('name')->all());
    }

    public function test_tagging_can_be_restricted(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::TAG, fn () => false);
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['is_read' => true]);

        $this->browse()->assertActionHidden(TestAction::make('editTags')->table($message));

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertActionHidden('editTags');
    }

    public function test_tag_resource_manages_the_catalogue(): void
    {
        Livewire::test(ListMailboxTags::class)
            ->assertCanSeeTableRecords([$this->open, $this->accounting, $this->foreign])
            ->callAction('create', ['name' => 'Offen', 'color' => 'red'])
            ->assertHasFormErrors(['name']);

        Livewire::test(ListMailboxTags::class)
            ->callAction('create', ['name' => 'Offen', 'color' => 'red', 'mailbox_id' => $this->mailbox->id])
            ->assertHasNoFormErrors();

        $this->assertTrue(MailboxTag::where('name', 'Offen')->where('mailbox_id', $this->mailbox->id)->exists());
    }

    public function test_tag_resource_requires_managing_tags(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        Livewire::test(ListMailboxTags::class)->assertForbidden();

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE_TAGS, fn () => true);

        Livewire::test(ListMailboxTags::class)->assertOk();
    }

    protected function browse(): Testable
    {
        return Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id]);
    }
}
