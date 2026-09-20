<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\AlertRule;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Events\SyncRunFinished;
use Cooolinho\FilamentMailbox\Events\SyncRunStarted;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\OAuthReconnectRequired;
use Cooolinho\FilamentMailbox\Exceptions\ProviderOperationFailed;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxAlerts\Pages\ListMailboxAlerts;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\SyncRunsRelationManager;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Cooolinho\FilamentMailbox\Monitoring\SyncErrorClassifier;
use Cooolinho\FilamentMailbox\Notifications\MailboxAlertNotification;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use RuntimeException;

class MonitoringTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Projects', 'Projects', '/'))
            ->addMessage('INBOX', 1)
            ->addMessage('INBOX', 2);

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support']);
        $this->mailbox->users()->attach($this->user);
    }

    public function test_a_successful_job_records_a_run(): void
    {
        Event::fake([SyncRunStarted::class, SyncRunFinished::class]);

        $this->travelTo(now()->startOfMinute());
        $job = new SyncMailboxJob($this->mailbox);
        $this->travel(90)->seconds();

        app()->call([$job, 'handle']);

        $run = MailboxSyncRun::sole();
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(SyncTrigger::Schedule, $run->trigger);
        $this->assertSame(90_000, $run->queue_wait_ms);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertSame(2, $run->folders);
        $this->assertSame(2, $run->imported);
        $this->assertNull($run->error_type);
        $this->assertSame(['INBOX', 'Projects'], array_keys($run->folder_stats));
        $this->assertSame(2, $run->folder_stats['INBOX']['imported']);
        $this->assertSame(['folders', 'subscribedFolders', 'changes', 'folderStatistics', 'labels'], array_keys($run->provider_stats));
        $this->assertSame(2, $run->provider_stats['changes']['count']);

        Event::assertDispatched(SyncRunStarted::class);
        Event::assertDispatched(SyncRunFinished::class);
    }

    public function test_updates_and_deletions_are_counted(): void
    {
        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $this->provider->setSeen('INBOX', 1, true);
        unset($this->provider->messages['INBOX'][2]);

        app()->call([new SyncMailboxJob($this->mailbox, SyncTrigger::Manual), 'handle']);

        $run = MailboxSyncRun::latest('id')->first();
        $this->assertSame(SyncTrigger::Manual, $run->trigger);
        $this->assertSame(1, $run->updated);
        $this->assertSame(1, $run->deleted);
        $this->assertSame(0, $run->imported);
    }

    public function test_a_failing_folder_makes_the_run_partial(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function changes(\Cooolinho\FilamentMailbox\Data\FolderIdentifier $folder, \Cooolinho\FilamentMailbox\Data\SyncCursor $cursor, int $limit): \Cooolinho\FilamentMailbox\Data\FolderChanges
            {
                if ($folder->remoteId === 'Projects') {
                    throw new RuntimeException('Connection timed out for anna@example.com');
                }

                return parent::changes($folder, $cursor, $limit);
            }
        };
        $failing->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))->addFolder(new FolderData('Projects', 'Projects', '/'));
        $this->app->instance(\Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory::class, new \Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory($failing));

        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $run = MailboxSyncRun::sole();
        $this->assertSame(SyncRunStatus::Partial, $run->status);
        $this->assertSame(SyncErrorType::Timeout, $run->error_type);
        $this->assertStringNotContainsString('anna@example.com', $run->error);
        $this->assertSame('timeout', $run->folder_stats['Projects']['error_type']);
        $this->assertNull($run->folder_stats['INBOX']['error_type']);
        $this->assertSame(1, $run->provider_stats['changes']['errors']);
    }

    public function test_a_failed_connection_records_a_failed_run_with_correlated_logs(): void
    {
        $handler = new TestHandler;
        config(['logging.channels.mailbox-test' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'filament-mailbox.sync.log_channel' => 'mailbox-test']);
        Log::channel('mailbox-test')->getLogger()->setHandlers([$handler]);

        $this->provider->connectionException = new RuntimeException('server down');

        try {
            app()->call([new SyncMailboxJob($this->mailbox), 'handle']);
            $this->fail('The job did not fail.');
        } catch (SyncFailed) {
        }

        $run = MailboxSyncRun::sole();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame(SyncErrorType::Unknown, $run->error_type);
        $this->assertSame('server down', $run->error);

        $record = $handler->getRecords()[0];
        $this->assertSame($run->uuid, $record->context['sync_run_id']);
        $this->assertSame($this->mailbox->id, $record->context['mailbox_id']);
    }

    public function test_monitoring_can_be_disabled(): void
    {
        config(['filament-mailbox.monitoring.enabled' => false]);

        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $this->assertSame(0, MailboxSyncRun::count());
        $this->assertSame(2, $this->mailbox->messages()->count());
    }

    public function test_errors_are_classified(): void
    {
        $classifier = new SyncErrorClassifier;

        $this->assertSame(SyncErrorType::Authentication, $classifier->classify(new OAuthReconnectRequired('Reconnect the account.')));
        $this->assertSame(SyncErrorType::Authentication, $classifier->classify(new ConnectionFailed('Connection to host:993 failed: [AUTHENTICATIONFAILED] Invalid credentials')));
        $this->assertSame(SyncErrorType::Authentication, $classifier->classify(SyncFailed::because('reconnect', permanent: true)));
        $this->assertSame(SyncErrorType::Connection, $classifier->classify(new ConnectionFailed('Connection to host:993 failed: refused')));
        $this->assertSame(SyncErrorType::Connection, $classifier->classify(SyncFailed::because('refused', cause: ConnectionFailed::class)));
        $this->assertSame(SyncErrorType::Timeout, $classifier->classify(new RuntimeException('Stream timed out')));
        $this->assertSame(SyncErrorType::RateLimited, $classifier->classify(new RuntimeException('HTTP 429 Too Many Requests')));
        $this->assertSame(SyncErrorType::Protocol, $classifier->classify(new ProviderOperationFailed('Mailbox operation "fetch" failed: BAD')));
        $this->assertSame(SyncErrorType::Unknown, $classifier->classify(new RuntimeException('boom')));
        $this->assertSame(SyncErrorType::Connection, $classifier->classifyMessage('Connection reset'));
    }

    public function test_authentication_failures_alert_immediately_and_success_resolves(): void
    {
        Notification::fake();
        $this->provider->connectionException = new RuntimeException('LOGIN failed');

        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);

        $alert = MailboxAlert::sole();
        $this->assertSame(AlertRule::Authentication, $alert->rule);
        $this->assertNotNull($alert->notified_at);
        Notification::assertSentTo($this->user, MailboxAlertNotification::class, fn (MailboxAlertNotification $notification, array $channels): bool => ! $notification->resolved && $channels === ['database']);

        // A second failure does not open a duplicate.
        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);
        $this->assertSame(1, MailboxAlert::count());

        $this->provider->connectionException = null;
        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $this->assertNotNull($alert->refresh()->resolved_at);
        Notification::assertSentTo($this->user, MailboxAlertNotification::class, fn (MailboxAlertNotification $notification): bool => $notification->resolved);
    }

    public function test_consecutive_failures_open_an_alert(): void
    {
        Notification::fake();
        $this->provider->connectionException = new RuntimeException('server down');

        foreach (range(1, 2) as $attempt) {
            rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);
        }

        $this->assertSame(0, MailboxAlert::count());

        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);

        $this->assertSame(AlertRule::ConsecutiveFailures, MailboxAlert::sole()->rule);
        $this->assertStringContainsString('Support', MailboxAlert::sole()->message);
    }

    public function test_the_monitor_command_detects_stale_mailboxes_and_hanging_runs(): void
    {
        Notification::fake();

        $this->mailbox->forceFill(['last_synced_at' => now()->subMinutes(10)])->save();
        $hanging = MailboxSyncRun::query()->create(['uuid' => 'hanging', 'mailbox_id' => $this->mailbox->id, 'trigger' => SyncTrigger::Schedule, 'status' => SyncRunStatus::Running, 'started_at' => now()->subHours(2)]);

        $this->artisan('mailbox:monitor')->assertSuccessful();

        $this->assertSame(0, MailboxAlert::count());
        $this->assertSame(SyncRunStatus::Failed, $hanging->refresh()->status);
        $this->assertSame(SyncErrorType::Timeout, $hanging->error_type);

        $this->travel(10)->minutes();
        $this->artisan('mailbox:monitor')->assertSuccessful();

        $alert = MailboxAlert::sole();
        $this->assertSame(AlertRule::Stale, $alert->rule);

        $this->mailbox->forceFill(['last_synced_at' => now()])->save();
        $this->artisan('mailbox:monitor')->assertSuccessful();

        $this->assertNotNull($alert->refresh()->resolved_at);
    }

    public function test_alerts_are_sent_by_mail_and_to_slack(): void
    {
        config([
            'filament-mailbox.monitoring.alerts.channels' => ['mail', 'slack'],
            'filament-mailbox.monitoring.alerts.mail_to' => 'ops@example.com',
            'filament-mailbox.monitoring.alerts.slack_webhook' => 'https://hooks.slack.com/services/T/B/X',
        ]);
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $this->provider->connectionException = new RuntimeException('LOGIN failed');

        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.slack.com/services/T/B/X' && str_contains($request['text'], 'Support'));

        Notification::fake();
        MailboxAlert::query()->update(['resolved_at' => now()]);
        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);

        Notification::assertSentOnDemand(MailboxAlertNotification::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'ops@example.com');
        Notification::assertSentTo($this->user, MailboxAlertNotification::class, fn ($notification, array $channels): bool => $channels === ['mail']);
    }

    public function test_prune_command_removes_old_runs_and_resolved_alerts(): void
    {
        $old = MailboxSyncRun::query()->create(['uuid' => 'old', 'mailbox_id' => $this->mailbox->id, 'trigger' => SyncTrigger::Schedule, 'status' => SyncRunStatus::Succeeded, 'started_at' => now()->subDays(40)]);
        $recent = MailboxSyncRun::query()->create(['uuid' => 'recent', 'mailbox_id' => $this->mailbox->id, 'trigger' => SyncTrigger::Schedule, 'status' => SyncRunStatus::Succeeded, 'started_at' => now()->subDay()]);
        $resolved = MailboxAlert::query()->create(['mailbox_id' => $this->mailbox->id, 'rule' => AlertRule::Stale, 'severity' => 'warning', 'message' => 'x', 'opened_at' => now()->subDays(45), 'resolved_at' => now()->subDays(40)]);
        $open = MailboxAlert::query()->create(['mailbox_id' => $this->mailbox->id, 'rule' => AlertRule::Stale, 'severity' => 'warning', 'message' => 'x', 'opened_at' => now()->subDays(45)]);

        $this->artisan('mailbox:prune-monitoring')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelMissing($resolved);
        $this->assertModelExists($open);
    }

    public function test_metrics_endpoint(): void
    {
        MailboxSyncRun::query()->create(['uuid' => 'a', 'mailbox_id' => $this->mailbox->id, 'trigger' => SyncTrigger::Schedule, 'status' => SyncRunStatus::Succeeded, 'started_at' => now(), 'duration_ms' => 2500]);
        MailboxSyncRun::query()->create(['uuid' => 'b', 'mailbox_id' => $this->mailbox->id, 'trigger' => SyncTrigger::Schedule, 'status' => SyncRunStatus::Failed, 'started_at' => now(), 'duration_ms' => 40_000, 'error_type' => SyncErrorType::Connection]);
        $this->mailbox->forceFill(['last_synced_at' => now()])->save();

        $this->get('/filament-mailbox/metrics')->assertNotFound();

        config(['filament-mailbox.monitoring.prometheus.enabled' => true, 'filament-mailbox.monitoring.prometheus.token' => 'secret-token']);

        $this->get('/filament-mailbox/metrics')->assertUnauthorized();
        $this->withToken('wrong')->get('/filament-mailbox/metrics')->assertUnauthorized();

        $body = $this->withToken('secret-token')->get('/filament-mailbox/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->getContent();

        $this->assertStringContainsString('filament_mailbox_sync_runs{status="succeeded"} 1', $body);
        $this->assertStringContainsString('filament_mailbox_sync_runs{status="failed"} 1', $body);
        $this->assertStringContainsString('filament_mailbox_sync_duration_seconds_bucket{le="5"} 1', $body);
        $this->assertStringContainsString('filament_mailbox_sync_duration_seconds_bucket{le="+Inf"} 2', $body);
        $this->assertStringContainsString('filament_mailbox_sync_duration_seconds_sum 42.5', $body);
        $this->assertStringContainsString('filament_mailbox_provider_errors{type="connection"} 1', $body);
        $this->assertStringNotContainsString('last_success_timestamp', $body);
        $this->assertStringNotContainsString('Support', $body);

        config(['filament-mailbox.monitoring.prometheus.mailbox_labels' => true]);

        $this->withToken('secret-token')->get('/filament-mailbox/metrics')
            ->assertSee('filament_mailbox_last_success_timestamp{mailbox="'.$this->mailbox->id.'"}', false);
    }

    public function test_sync_history_and_alert_resource(): void
    {
        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);
        $run = MailboxSyncRun::sole();
        $alert = MailboxAlert::query()->create(['mailbox_id' => $this->mailbox->id, 'rule' => AlertRule::Stale, 'severity' => 'warning', 'message' => 'stale', 'opened_at' => now()]);

        Livewire::test(SyncRunsRelationManager::class, ['ownerRecord' => $this->mailbox, 'pageClass' => EditMailbox::class])
            ->assertCanSeeTableRecords([$run])
            ->mountAction(TestAction::make('view')->table($run))
            ->assertMountedActionModalSee(['INBOX', 'changes', $run->uuid]);

        Livewire::test(ListMailboxAlerts::class)
            ->assertCanSeeTableRecords([$alert])
            ->callAction(TestAction::make('acknowledge')->table($alert))
            ->assertNotified(__('filament-mailbox::mailbox.monitoring.alerts.acknowledged'));

        $this->assertNotNull($alert->refresh()->resolved_at);
        $this->assertSame($this->user->id, $alert->acknowledged_by);

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        Livewire::test(ListMailboxAlerts::class)->assertForbidden();
    }

    public function test_manual_sync_is_queued_with_its_trigger(): void
    {
        Queue::fake();

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])->callAction('sync');

        Queue::assertPushed(SyncMailboxJob::class, fn (SyncMailboxJob $job): bool => $job->trigger === SyncTrigger::Manual);
    }
}
