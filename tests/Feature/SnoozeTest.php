<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessagesSnoozed;
use Cooolinho\FilamentMailbox\Events\MessagesUnsnoozed;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Jobs\WakeSnoozedMessagesJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\SnoozeService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Filament\Navigation\NavigationItem;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class SnoozeTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00:00', 'UTC'));

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
    }

    public function test_snoozed_messages_are_hidden_in_the_folder_and_listed_in_the_snoozed_view(): void
    {
        $snoozed = $this->message(1, ['subject' => 'Later']);
        $visible = $this->message(2);
        $foreign = MailboxMessage::factory()->create(['snoozed_until' => now()->addDay()]);

        $this->browse()
            ->callAction(TestAction::make('snooze')->table($snoozed), ['preset' => 'tomorrow'])
            ->assertNotified();

        $this->assertSame('2026-09-23 08:00:00', $snoozed->refresh()->snoozed_until->toDateTimeString());
        $this->assertSame($this->user->id, $snoozed->snoozed_by);

        $this->browse()
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$snoozed]);

        $this->browse(['view' => 'snoozed'])
            ->assertCanSeeTableRecords([$snoozed])
            ->assertCanNotSeeTableRecords([$visible, $foreign])
            ->assertTableColumnVisible('snoozed_until')
            ->assertSee(__('filament-mailbox::mailbox.snooze.label'));
    }

    public function test_snoozed_navigation_item_shows_the_count(): void
    {
        $this->message(1, ['snoozed_until' => now()->addHour()]);
        $this->message(2, ['snoozed_until' => now()->addDay()]);
        $this->message(3, ['snoozed_until' => now()->subMinute()]);

        $page = $this->browse(['view' => 'snoozed'])->instance();
        $items = collect(array_values($page->getCachedSubNavigation())[0]->getItems());
        $snoozed = $items->first(fn (NavigationItem $item) => $item->getLabel() === 'Snoozed');

        $this->assertSame(['Inbox', 'Starred', 'Snoozed'], $items->map(fn (NavigationItem $item) => $item->getLabel())->all());
        $this->assertSame('2', $snoozed->getBadge());
        $this->assertTrue($snoozed->isActive());
        $this->assertTrue($page->isSnoozedView());
    }

    public function test_bulk_snooze_and_unsnooze(): void
    {
        $messages = collect([1, 2])->map(fn (int $uid) => $this->message($uid));

        $this->browse()
            ->selectTableRecords($messages)
            ->callAction(TestAction::make('snooze')->table()->bulk(), ['preset' => 'later_today']);

        $this->assertSame(2, MailboxMessage::query()->snoozed()->count());
        $this->assertSame('2026-09-22 13:00:00', $messages->first()->refresh()->snoozed_until->toDateTimeString());

        $this->browse(['view' => 'snoozed'])
            ->selectTableRecords($messages)
            ->callAction(TestAction::make('unsnooze')->table()->bulk())
            ->assertNotified(__('filament-mailbox::mailbox.snooze.unsnoozed'));

        $this->assertSame(0, MailboxMessage::query()->whereNotNull('snoozed_until')->count());
    }

    public function test_custom_time_is_entered_in_the_panel_time_zone_and_stored_in_utc(): void
    {
        FilamentTimezone::set('Europe/Berlin');
        $message = $this->message(1);

        $this->browse()
            ->callAction(TestAction::make('snooze')->table($message), ['preset' => 'custom', 'until' => '2026-10-01 09:30:00']);

        $this->assertSame('2026-10-01 07:30:00', $message->refresh()->snoozed_until->toDateTimeString());
    }

    public function test_presets_are_calculated_in_the_panel_time_zone(): void
    {
        $until = app(SnoozeService::class)->resolvePreset('tomorrow', 'Europe/Berlin');

        $this->assertSame('2026-09-23 06:00:00', $until->utc()->toDateTimeString());
    }

    public function test_times_in_the_past_or_beyond_one_year_are_rejected(): void
    {
        $message = $this->message(1);
        $snooze = app(SnoozeService::class);

        foreach ([now()->subMinute(), now()->addDays(400)] as $until) {
            try {
                $snooze->snooze($message, $until, $this->user);
                $this->fail('Expected an invalid snooze time.');
            } catch (InvalidArgumentException) {
                $this->assertNull($message->refresh()->snoozed_until);
            }
        }
    }

    public function test_message_page_snoozes_and_redirects_to_the_folder(): void
    {
        $message = $this->message(1, ['is_read' => true]);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->callAction('snooze', ['preset' => 'next_week'])
            ->assertRedirect();

        $this->assertSame('2026-09-28 08:00:00', $message->refresh()->snoozed_until->toDateTimeString());

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $message->id])
            ->assertActionVisible('unsnooze')
            ->assertActionHidden('snooze')
            ->callAction('unsnooze');

        $this->assertNull($message->refresh()->snoozed_until);
    }

    public function test_waking_marks_unread_moves_to_the_top_and_notifies_once(): void
    {
        Event::fake([MessagesUnsnoozed::class]);

        $older = $this->message(1, ['received_at' => now()->subDays(3), 'is_read' => true]);
        $newer = $this->message(2, ['received_at' => now()->subHour()]);
        app(SnoozeService::class)->snooze($older, now()->addHour(), $this->user);
        $this->provider->setSeen('INBOX', 1, true);

        $this->travel(2)->hours();
        $this->artisan('mailbox:wake-snoozed', ['--now' => true])->assertSuccessful();

        $older->refresh();
        $this->assertNull($older->snoozed_until);
        $this->assertFalse($older->is_read);
        $this->assertFalse($this->provider->messages['INBOX'][1]->flags->seen);
        $this->assertTrue($older->sort_at->equalTo(now()->startOfSecond()));
        $this->assertCount(1, $this->notificationsOf($this->user));
        Event::assertDispatched(MessagesUnsnoozed::class, fn (MessagesUnsnoozed $event) => $event->woken && $event->messageIds === [$older->id]);

        $this->browse()->assertCanSeeTableRecords([$older, $newer], inOrder: true);

        // A second run (or job) does nothing.
        $this->assertSame(0, app(SnoozeService::class)->wake([$older->id]));
        $this->assertCount(1, $this->notificationsOf($this->user));
    }

    public function test_command_queues_jobs_for_due_messages(): void
    {
        Bus::fake();

        $due = $this->message(1, ['snoozed_until' => now()->subMinute()]);
        $this->message(2, ['snoozed_until' => now()->addHour()]);

        $this->artisan('mailbox:wake-snoozed')->assertSuccessful();

        Bus::assertDispatched(WakeSnoozedMessagesJob::class, fn (WakeSnoozedMessagesJob $job) => $job->messageIds === [$due->id]);
    }

    public function test_users_without_access_are_not_notified(): void
    {
        $other = User::make('Former member');
        $message = $this->message(1, ['snoozed_until' => now()->subMinute(), 'snoozed_by' => $other->id]);

        app(SnoozeService::class)->wake([$message->id]);

        $this->assertNull($message->refresh()->snoozed_until);
        $this->assertSame([], $this->notificationsOf($other));
    }

    public function test_server_errors_on_wake_keep_the_message_unread_locally(): void
    {
        $this->provider->capabilities = [];
        $message = $this->message(1, ['is_read' => true, 'snoozed_until' => now()->subMinute(), 'snoozed_by' => $this->user->id]);

        $this->assertSame(1, app(SnoozeService::class)->wake([$message->id]));

        $this->assertFalse($message->refresh()->is_read);
    }

    public function test_synchronisation_keeps_the_snooze(): void
    {
        Event::fake([MessagesSnoozed::class]);

        $this->provider->addMessage('INBOX', new MessageData('1', messageId: '<a@example.com>', subject: 'Synced'));
        $sync = app(SyncService::class);
        $sync->syncFolder($this->inbox, $this->provider);
        $message = MailboxMessage::sole();

        app(SnoozeService::class)->snooze($message, now()->addDay(), $this->user);
        Event::assertDispatched(MessagesSnoozed::class);

        $this->provider->messages['INBOX'][1] = $this->provider->messages['INBOX'][1]->with(['flags' => new MessageFlags(seen: true)]);
        $sync->syncFolder($this->inbox->refresh(), $this->provider);

        $message->refresh();
        $this->assertTrue($message->is_read);
        $this->assertSame('2026-09-23 10:00:00', $message->snoozed_until->toDateTimeString());
    }

    public function test_server_variant_moves_to_the_snoozed_folder_and_back(): void
    {
        config(['filament-mailbox.snooze.server_folder' => 'Snoozed']);
        $message = $this->message(1);

        app(SnoozeService::class)->snooze($message, now()->addHour(), $this->user);

        $folder = $this->mailbox->folders()->where('name', 'Snoozed')->sole();
        $message->refresh();
        $this->assertSame($folder->id, $message->folder_id);
        $this->assertSame($this->inbox->id, $message->snoozed_from_folder_id);
        $this->assertCount(1, $this->provider->messages['Snoozed']);

        $this->travel(2)->hours();
        app(SnoozeService::class)->wake([$message->id]);

        $message->refresh();
        $this->assertSame($this->inbox->id, $message->folder_id);
        $this->assertNull($message->snoozed_from_folder_id);
        $this->assertCount(0, $this->provider->messages['Snoozed']);
    }

    public function test_snooze_can_be_disabled(): void
    {
        config(['filament-mailbox.snooze.enabled' => false]);
        $message = $this->message(1);

        $page = $this->browse(['view' => 'snoozed'])
            ->assertTableActionHidden('snooze', $message);

        $this->assertFalse($page->instance()->isSnoozedView());
    }

    /**
     * @return array<int, object>
     */
    protected function notificationsOf(User $user): array
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->get()->all();
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
        $this->provider->addMessage('INBOX', new MessageData((string) $uid));

        return MailboxMessage::factory()->for($this->inbox, 'folder')->create(['remote_id' => '1:'.$uid, ...$attributes]);
    }
}
