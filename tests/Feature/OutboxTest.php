<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\OutgoingStatus;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\OutgoingMessageFailed;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListOutgoingMessages;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Services\MailSender;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;

class OutboxTest extends TestCase
{
    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        // Today: Livewire removes temporary uploads older than a day (real file times).
        $this->travelTo(CarbonImmutable::now('UTC')->setTime(10, 0));

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support', 'email' => 'support@example.com']);
        $this->mailbox->users()->attach($this->user);
    }

    public function test_immediate_messages_are_sent_in_the_request_and_recorded(): void
    {
        $this->compose(['subject' => 'Right away']);

        Mail::assertSent(OutgoingMessage::class);

        $outgoing = MailboxOutgoingMessage::query()->sole();
        $this->assertSame(OutgoingStatus::Sent, $outgoing->status);
        $this->assertSame(1, $outgoing->attempts);
        $this->assertNotNull($outgoing->sent_at);
        $this->assertStringEndsWith('@example.com', $outgoing->message_id);
    }

    public function test_failure_when_sending_right_away_keeps_nothing_in_the_outbox(): void
    {
        $this->failingSender();

        $this->compose()->assertNotified(__('filament-mailbox::mailbox.actions.compose.failure'));

        $this->assertSame(0, MailboxOutgoingMessage::query()->count());
    }

    public function test_scheduled_message_is_stored_and_sent_when_due(): void
    {
        $this->compose([
            'subject' => 'Monday',
            'bcc' => ['audit@example.com'],
            'send_at_preset' => 'tomorrow_morning',
            'attachments' => [UploadedFile::fake()->createWithContent('notes.txt', 'some notes')],
        ])->assertNotified(__('filament-mailbox::mailbox.outbox.scheduled', ['time' => now()->addDay()->setTime(8, 0)->translatedFormat('D, j. M Y, H:i')]));

        Mail::assertNothingSent();

        $outgoing = MailboxOutgoingMessage::query()->sole();
        $attachment = $outgoing->attachments()->sole();
        $this->assertSame(OutgoingStatus::Scheduled, $outgoing->status);
        $this->assertSame(now()->addDay()->setTime(8, 0)->toDateTimeString(), $outgoing->send_at->toDateTimeString());
        $this->assertSame(['audit@example.com'], $outgoing->bcc);
        $this->assertStringNotContainsString('audit@example.com', (string) DB::table('mailbox_outgoing_messages')->value('bcc'));
        Storage::disk('local')->assertExists($attachment->storage_path);

        // Not yet due: the fallback command does nothing.
        $this->artisan('mailbox:send-due')->assertSuccessful();
        Mail::assertNothingSent();

        $this->travelTo(now()->addDay()->setTime(8, 0, 30));
        $this->artisan('mailbox:send-due')->assertSuccessful();

        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->hasBcc('audit@example.com')
            && $mail->data->messageId === $outgoing->message_id
            && $mail->data->attachments[0]->contents === 'some notes');

        $outgoing->refresh();
        $this->assertSame(OutgoingStatus::Sent, $outgoing->status);
        $this->assertSame(0, $outgoing->attachments()->count());
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }

    public function test_a_message_is_never_sent_twice(): void
    {
        $outgoing = $this->queue(now()->addMinute());
        $this->travel(2)->minutes();

        $outbox = app(OutboxService::class);
        $copy = MailboxOutgoingMessage::query()->find($outgoing->id);

        $this->assertTrue($outbox->deliver($outgoing));
        $this->assertFalse($outbox->deliver($copy));
        Mail::assertSentCount(1);
    }

    public function test_undo_window_cancels_the_message(): void
    {
        config(['filament-mailbox.outbox.undo_seconds' => 10]);

        $this->compose()->assertNotified(__('filament-mailbox::mailbox.outbox.sending'));

        $outgoing = MailboxOutgoingMessage::query()->sole();
        $this->assertSame(OutgoingStatus::Scheduled, $outgoing->status);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->dispatch(OutboxService::CANCEL_EVENT, id: $outgoing->id)
            ->assertNotified(__('filament-mailbox::mailbox.outbox.cancelled'));

        $this->travel(1)->minutes();
        $this->artisan('mailbox:send-due');

        $this->assertSame(OutgoingStatus::Cancelled, $outgoing->refresh()->status);
        Mail::assertNothingSent();
    }

    public function test_other_users_cannot_cancel_a_message(): void
    {
        $this->withoutManagers();
        $outgoing = $this->queue(now()->addHour());
        $other = User::make('Other');
        $this->mailbox->users()->attach($other);
        $this->actingAs($other);

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->dispatch(OutboxService::CANCEL_EVENT, id: $outgoing->id);

        $this->assertSame(OutgoingStatus::Scheduled, $outgoing->refresh()->status);
    }

    public function test_failed_attempts_are_retried_with_backoff_and_fail_finally(): void
    {
        Event::fake([OutgoingMessageFailed::class]);
        $this->failingSender();

        $outgoing = $this->queue(now());
        $messageId = $outgoing->message_id;
        $outbox = app(OutboxService::class);

        $this->assertFalse($outbox->deliver($outgoing));
        $outgoing->refresh();
        $this->assertSame(OutgoingStatus::Scheduled, $outgoing->status);
        $this->assertSame(1, $outgoing->attempts);
        $this->assertSame('SMTP down', $outgoing->last_error);
        $this->assertSame(now()->addMinute()->toDateTimeString(), $outgoing->send_at->toDateTimeString());

        $this->travel(1)->minutes();
        $outbox->deliver($outgoing->refresh());
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $outgoing->refresh()->send_at->toDateTimeString());

        $this->travel(5)->minutes();
        $outbox->deliver($outgoing->refresh());

        $outgoing->refresh();
        $this->assertSame(OutgoingStatus::Failed, $outgoing->status);
        $this->assertSame(3, $outgoing->attempts);
        $this->assertSame($messageId, $outgoing->message_id);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->user->id)->count());
        Event::assertDispatched(OutgoingMessageFailed::class);
    }

    public function test_revoked_permission_fails_the_message_without_sending(): void
    {
        $outgoing = $this->queue(now()->addHour());

        $this->mailbox->users()->detach($this->user);
        app(MailboxAccess::class)->flush();

        $this->travel(2)->hours();
        app(OutboxService::class)->deliver($outgoing);

        $outgoing->refresh();
        $this->assertSame(OutgoingStatus::Failed, $outgoing->status);
        $this->assertSame(__('filament-mailbox::mailbox.outbox.errors.not_allowed'), $outgoing->last_error);
        Mail::assertNothingSent();
    }

    public function test_messages_hanging_in_sending_are_failed(): void
    {
        $outgoing = $this->queue(now());
        MailboxOutgoingMessage::query()->whereKey($outgoing->id)->update(['status' => 'sending', 'updated_at' => now()->subHour()]);

        $this->artisan('mailbox:send-due');

        $this->assertSame(OutgoingStatus::Failed, $outgoing->refresh()->status);
        Mail::assertNothingSent();
    }

    public function test_scheduled_forward_marks_the_original_when_it_is_sent(): void
    {
        $this->fakeProvider()->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));
        $original = MailboxMessage::factory()
            ->for(MailboxFolder::factory()->for($this->mailbox)->inbox(), 'folder')
            ->create(['remote_id' => '1:1', 'is_read' => true, 'message_id' => '<original@example.com>']);

        Livewire::test(ViewMessage::class, ['record' => $this->mailbox->id, 'message' => $original->id])
            ->callAction('forward', data: ['to' => ['jane@example.com'], 'format' => 'text', 'body' => 'FYI', 'send_at_preset' => 'in_one_hour'])
            ->assertHasNoFormErrors();

        $this->assertNull($original->refresh()->forwarded_at);

        $this->travel(61)->minutes();
        $this->artisan('mailbox:send-due');

        $this->assertNotNull($original->refresh()->forwarded_at);
    }

    public function test_custom_send_time_in_the_past_is_rejected(): void
    {
        $this->compose(['send_at_preset' => 'custom', 'send_at' => now()->subDay()->toDateTimeString()]);

        $this->assertSame(0, MailboxOutgoingMessage::query()->count());
    }

    public function test_outbox_page_lists_own_messages_with_actions(): void
    {
        $this->withoutManagers();
        $own = $this->queue(now()->addDay());
        $foreign = $this->queue(now()->addDay(), User::make('Colleague'));

        $page = Livewire::test(ListOutgoingMessages::class, ['record' => $this->mailbox->id])
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        $page->callAction(TestAction::make('edit')->table($own), ['to' => ['max@example.com'], 'cc' => [], 'bcc' => [], 'subject' => 'Changed', 'body' => 'New body'])
            ->assertNotified(__('filament-mailbox::mailbox.outbox.updated'));
        $this->assertSame(['max@example.com'], $own->refresh()->to);
        $this->assertSame('New body', $own->body);

        $page->callAction(TestAction::make('cancel')->table($own));
        $this->assertSame(OutgoingStatus::Cancelled, $own->refresh()->status);

        $page->callAction(TestAction::make('sendNow')->table($own));
        $this->assertSame(OutgoingStatus::Sent, $own->refresh()->status);
        Mail::assertSent(OutgoingMessage::class, fn (OutgoingMessage $mail): bool => $mail->hasTo('max@example.com') && $mail->hasSubject('Changed'));

        $page->assertTableActionHidden('cancel', $own)->assertTableActionHidden('edit', $own);
    }

    public function test_failed_messages_can_be_retried(): void
    {
        $outgoing = $this->queue(now());
        MailboxOutgoingMessage::query()->whereKey($outgoing->id)->update(['status' => 'failed', 'attempts' => 3]);

        Livewire::test(ListOutgoingMessages::class, ['record' => $this->mailbox->id])
            ->callAction(TestAction::make('retry')->table($outgoing))
            ->assertNotified(__('filament-mailbox::mailbox.outbox.retrying'));

        $this->assertSame(OutgoingStatus::Sent, $outgoing->refresh()->status);
        $this->assertSame(1, $outgoing->attempts);
    }

    public function test_browse_page_shows_the_outbox_badge(): void
    {
        $this->queue(now()->addDay());

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->assertActionVisible('outbox');

        $this->assertSame(1, ListOutgoingMessages::attentionCount($this->mailbox));
    }

    public function test_prune_removes_old_finished_messages(): void
    {
        $old = $this->queue(now());
        MailboxOutgoingMessage::query()->whereKey($old->id)->update(['status' => 'sent', 'updated_at' => now()->subDays(40)]);
        $pending = $this->queue(now()->addDay());

        $this->artisan('mailbox:prune-outbox')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($pending);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function compose(array $data = []): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->callAction('compose', data: ['to' => ['jane@example.com'], 'subject' => 'Hello', 'format' => 'text', 'body' => 'Body', ...$data]);
    }

    protected function queue(\DateTimeInterface $sendAt, ?User $user = null): MailboxOutgoingMessage
    {
        config(['filament-mailbox.outbox.send_immediately_inline' => false]);

        // Sync queue: due jobs would run right away, so they are queued in the future and made due afterwards.
        $outgoing = app(OutboxService::class)->send($this->mailbox, new OutgoingMessageData(['jane@example.com'], 'Queued', 'Body'), $user ?? $this->user, now()->addYear());

        MailboxOutgoingMessage::query()->whereKey($outgoing->id)->update(['send_at' => $sendAt]);

        config(['filament-mailbox.outbox.send_immediately_inline' => true]);

        return $outgoing->refresh();
    }

    protected function withoutManagers(): void
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn (): bool => false);
    }

    protected function failingSender(): void
    {
        $this->app->instance(MailSender::class, new class($this->app) extends MailSender
        {
            public function send(Mailbox $mailbox, OutgoingMessageData $data): void
            {
                throw new RuntimeException('SMTP down');
            }
        });
    }
}
