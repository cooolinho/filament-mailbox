<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\AlertRule;
use Cooolinho\FilamentMailbox\Enums\HealthCheckStatus;
use Cooolinho\FilamentMailbox\Enums\MailboxHealth;
use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxHealth as MailboxHealthPage;
use Cooolinho\FilamentMailbox\Filament\Widgets\MailboxHealthStatsWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\SystemChecksWidget;
use Cooolinho\FilamentMailbox\Health\Checks\AttachmentDiskCheck;
use Cooolinho\FilamentMailbox\Health\Checks\FailedJobsCheck;
use Cooolinho\FilamentMailbox\Health\Checks\OAuthConnectionsCheck;
use Cooolinho\FilamentMailbox\Health\Checks\QueueHeartbeatCheck;
use Cooolinho\FilamentMailbox\Health\Checks\SchedulerHeartbeatCheck;
use Cooolinho\FilamentMailbox\Health\Checks\SearchEngineCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckRunner;
use Cooolinho\FilamentMailbox\Health\HealthStatistics;
use Cooolinho\FilamentMailbox\Health\MailboxHealthEvaluator;
use Cooolinho\FilamentMailbox\Jobs\MailboxHeartbeatJob;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\Monitoring\AlertEvaluator;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;

class HealthDashboardTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected MailboxHealthEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addMessage('INBOX', 1);

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support']);
        $this->evaluator = app(MailboxHealthEvaluator::class);
    }

    protected function recordRun(Mailbox $mailbox, SyncRunStatus $status, array $attributes = []): MailboxSyncRun
    {
        $run = MailboxSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'mailbox_id' => $mailbox->id,
            'trigger' => SyncTrigger::Schedule,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 1200,
            ...$attributes,
        ]);

        $this->evaluator->recordRun($run);

        return $run;
    }

    public function test_a_new_mailbox_is_unknown_and_inactive_mailboxes_are_inactive(): void
    {
        $this->assertSame(MailboxHealth::Unknown, $this->evaluator->evaluate($this->mailbox));

        $this->mailbox->update(['is_active' => false]);

        $this->assertSame(MailboxHealth::Inactive, $this->evaluator->evaluate($this->mailbox));
    }

    public function test_successful_runs_are_healthy_and_reset_failures(): void
    {
        $this->recordRun($this->mailbox, SyncRunStatus::Failed, ['error_type' => SyncErrorType::Connection]);
        $this->assertSame(MailboxHealth::Degraded->value, $this->mailbox->fresh()->health);
        $this->assertSame(1, $this->mailbox->fresh()->consecutive_failures);

        $this->recordRun($this->mailbox, SyncRunStatus::Succeeded);

        $mailbox = $this->mailbox->fresh();
        $this->assertSame(MailboxHealth::Healthy->value, $mailbox->health);
        $this->assertSame(0, $mailbox->consecutive_failures);
        $this->assertNotNull($mailbox->last_success_at);
        $this->assertSame('succeeded', $mailbox->last_run_status);
        $this->assertSame(1200, $mailbox->last_run_duration_ms);
        $this->assertNull($mailbox->last_error_type);
    }

    public function test_partial_runs_are_degraded_and_count_failing_folders(): void
    {
        $this->recordRun($this->mailbox, SyncRunStatus::Partial, [
            'error_type' => SyncErrorType::Timeout,
            'folder_stats' => [
                'INBOX' => ['duration_ms' => 1, 'imported' => 1, 'updated' => 0, 'deleted' => 0, 'error_type' => null, 'error' => null],
                'Archive' => ['duration_ms' => 1, 'imported' => 0, 'updated' => 0, 'deleted' => 0, 'error_type' => 'timeout', 'error' => 'x'],
            ],
        ]);

        $mailbox = $this->mailbox->fresh();
        $this->assertSame(MailboxHealth::Degraded->value, $mailbox->health);
        $this->assertSame(1, $mailbox->failing_folders);
        $this->assertSame('timeout', $mailbox->last_error_type);
        $this->assertNotNull($mailbox->last_success_at);
    }

    public function test_three_failures_in_a_row_are_failing(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->recordRun($this->mailbox, SyncRunStatus::Failed, ['error_type' => SyncErrorType::Connection]);
        }

        $this->assertSame(MailboxHealth::Failing->value, $this->mailbox->fresh()->health);
        $this->assertSame(3, $this->mailbox->fresh()->consecutive_failures);
    }

    public function test_an_open_critical_alert_is_failing(): void
    {
        $this->recordRun($this->mailbox, SyncRunStatus::Succeeded);
        app(AlertEvaluator::class)->open($this->mailbox, AlertRule::ConsecutiveFailures, 'Failing');

        $this->assertSame(MailboxHealth::Failing, $this->evaluator->refresh($this->mailbox->fresh()));
        // The table rating keeps the stored "failing" without querying alerts.
        $this->assertSame(MailboxHealth::Failing, $this->evaluator->current($this->mailbox->fresh()));
    }

    public function test_authentication_errors_and_broken_oauth_connections_require_a_reconnect(): void
    {
        $this->recordRun($this->mailbox, SyncRunStatus::Failed, ['error_type' => SyncErrorType::Authentication]);
        $this->assertSame(MailboxHealth::Reconnect->value, $this->mailbox->fresh()->health);

        $connection = OAuthConnection::factory()->create(['status' => OAuthConnectionStatus::NeedsReconnect]);
        $oauth = Mailbox::factory()->create(['auth_mode' => 'oauth', 'oauth_connection_id' => $connection->id]);
        $this->recordRun($oauth, SyncRunStatus::Succeeded);

        $this->assertSame(MailboxHealth::Reconnect->value, $oauth->fresh()->health);
    }

    public function test_stale_mailboxes_are_detected_at_runtime(): void
    {
        config(['filament-mailbox.monitoring.expected_sync_interval_minutes' => 5, 'filament-mailbox.monitoring.alerts.stale_factor' => 3]);

        $this->recordRun($this->mailbox, SyncRunStatus::Succeeded);

        $this->travel(14)->minutes();
        $this->assertSame(MailboxHealth::Healthy, $this->evaluator->current($this->mailbox->fresh()));

        $this->travel(2)->minutes();
        $this->assertSame(MailboxHealth::Stale, $this->evaluator->current($this->mailbox->fresh()));
        $this->assertSame(MailboxHealth::Stale, $this->evaluator->refresh($this->mailbox->fresh()));
        $this->assertSame(MailboxHealth::Stale->value, $this->mailbox->fresh()->health);
    }

    public function test_the_sync_run_listener_updates_the_denormalised_fields(): void
    {
        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $mailbox = $this->mailbox->fresh();
        $this->assertSame(MailboxHealth::Healthy->value, $mailbox->health);
        $this->assertSame('succeeded', $mailbox->last_run_status);
        $this->assertNotNull($mailbox->last_run_duration_ms);
    }

    public function test_heartbeat_checks_use_thresholds(): void
    {
        config(['filament-mailbox.health.queue_heartbeat' => ['warning_after' => 3, 'failed_after' => 10]]);
        $check = new QueueHeartbeatCheck;

        $this->assertSame(HealthCheckStatus::Failed, $check->run()->status);

        QueueHeartbeatCheck::beat();
        $this->assertSame(HealthCheckStatus::Ok, $check->run()->status);

        $this->travel(4)->minutes();
        $this->assertSame(HealthCheckStatus::Warning, $check->run()->status);

        $this->travel(7)->minutes();
        $this->assertSame(HealthCheckStatus::Failed, $check->run()->status);

        (new MailboxHeartbeatJob)->handle();
        $this->assertSame(HealthCheckStatus::Ok, $check->run()->status);
    }

    public function test_the_attachment_disk_check_writes_and_removes_a_probe_file(): void
    {
        Storage::fake('local');

        $result = (new AttachmentDiskCheck)->run();

        $this->assertSame(HealthCheckStatus::Ok, $result->status);
        $this->assertSame([], Storage::disk('local')->allFiles('mailbox/.health'));

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andThrow(new RuntimeException('Permission denied: /secret/path'));
        Storage::set('local', $disk);

        $result = (new AttachmentDiskCheck)->run();
        $this->assertSame(HealthCheckStatus::Failed, $result->status);
        $this->assertStringNotContainsString('/secret/path', $result->message);
    }

    public function test_the_search_and_oauth_checks(): void
    {
        $this->assertSame(HealthCheckStatus::Ok, (new SearchEngineCheck)->run()->status);

        $this->assertSame(HealthCheckStatus::Skipped, (new OAuthConnectionsCheck)->run()->status);

        $connection = OAuthConnection::factory()->create();
        Mailbox::factory()->create(['auth_mode' => 'oauth', 'oauth_connection_id' => $connection->id]);
        $this->assertSame(HealthCheckStatus::Ok, (new OAuthConnectionsCheck)->run()->status);

        $connection->update(['status' => OAuthConnectionStatus::Revoked]);
        $this->assertSame(HealthCheckStatus::Failed, (new OAuthConnectionsCheck)->run()->status);
    }

    public function test_the_failed_jobs_check_only_counts_recent_mailbox_jobs(): void
    {
        config(['queue.failed.database' => config('database.default')]);

        $this->assertSame(HealthCheckStatus::Skipped, (new FailedJobsCheck)->run()->status);

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $job = fn (string $class, $failedAt) => DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default',
            'payload' => json_encode(['displayName' => $class]), 'exception' => 'x', 'failed_at' => $failedAt,
        ]);

        $job('App\\Jobs\\Other', now());
        $job(SyncMailboxJob::class, now()->subDays(2));
        $this->assertSame(HealthCheckStatus::Ok, (new FailedJobsCheck)->run()->status);

        $job(SyncMailboxJob::class, now()->subHour());
        $result = (new FailedJobsCheck)->run();
        $this->assertSame(HealthCheckStatus::Warning, $result->status);
        $this->assertSame(1, $result->meta['count']);
    }

    public function test_the_monitor_command_runs_checks_writes_heartbeats_and_rates_mailboxes(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->mailbox->forceFill(['health' => 'healthy', 'last_success_at' => now()->subDay()])->save();

        $runner = app(HealthCheckRunner::class);
        $this->assertSame(HealthCheckStatus::Warning, $runner->results()['attachment_disk']->status);

        $this->artisan('mailbox:monitor')->assertSuccessful();

        Queue::assertPushed(MailboxHeartbeatJob::class);
        $this->assertNotNull(SchedulerHeartbeatCheck::lastBeat());
        $this->assertSame(MailboxHealth::Stale->value, $this->mailbox->fresh()->health);

        $results = $runner->results();
        $this->assertSame(HealthCheckStatus::Ok, $results['attachment_disk']->status);
        $this->assertSame(HealthCheckStatus::Ok, $results['scheduler']->status);
        // No worker processed the faked job.
        $this->assertSame(HealthCheckStatus::Failed, $results['queue']->status);
        $this->assertSame(HealthCheckStatus::Failed, $runner->worstStatus($results));
    }

    public function test_the_page_is_only_available_to_managers(): void
    {
        Livewire::test(MailboxHealthPage::class)->assertSuccessful();

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn () => false);

        $this->assertFalse(MailboxHealthPage::canAccess());
        $this->get(MailboxHealthPage::getUrl())->assertForbidden();
        $this->assertFalse(MailboxHealthStatsWidget::canView());
        $this->assertFalse(SystemChecksWidget::canView());
    }

    public function test_the_page_can_be_disabled(): void
    {
        config(['filament-mailbox.health.enabled' => false]);

        $this->assertFalse(MailboxHealthPage::canAccess());
    }

    public function test_the_table_lists_the_worst_status_first_and_filters(): void
    {
        $healthy = Mailbox::factory()->create(['name' => 'A healthy']);
        $this->recordRun($healthy, SyncRunStatus::Succeeded);
        $failing = Mailbox::factory()->create(['name' => 'B failing']);
        foreach (range(1, 3) as $ignored) {
            $this->recordRun($failing, SyncRunStatus::Failed, ['error_type' => SyncErrorType::Connection]);
        }
        $inactive = Mailbox::factory()->create(['name' => 'C inactive', 'is_active' => false]);
        $this->evaluator->refresh($inactive);

        Livewire::test(MailboxHealthPage::class)
            ->assertCanSeeTableRecords([$failing, $this->mailbox, $healthy, $inactive], inOrder: true)
            ->filterTable('health', [MailboxHealth::Failing->value])
            ->assertCanSeeTableRecords([$failing])
            ->assertCanNotSeeTableRecords([$healthy, $inactive]);

        $this->assertSame('1', MailboxHealthPage::getNavigationBadge());
    }

    public function test_actions_dispatch_synchronisations(): void
    {
        Queue::fake();
        $inactive = Mailbox::factory()->create(['is_active' => false]);

        Livewire::test(MailboxHealthPage::class)
            ->callAction(TestAction::make('sync')->table($this->mailbox))
            ->assertNotified();

        Queue::assertPushed(SyncMailboxJob::class, fn (SyncMailboxJob $job) => $job->mailbox->is($this->mailbox) && $job->trigger === SyncTrigger::Manual);

        Queue::fake();

        Livewire::test(MailboxHealthPage::class)
            ->selectTableRecords([$this->mailbox->id, $inactive->id])
            ->callAction(TestAction::make('sync')->table()->bulk());

        Queue::assertPushed(SyncMailboxJob::class, 1);
    }

    public function test_connections_can_be_tested_from_the_table(): void
    {
        Livewire::test(MailboxHealthPage::class)
            ->callAction(TestAction::make('testConnection')->table($this->mailbox))
            ->assertNotified(__('filament-mailbox::mailbox.actions.test_connection.success'));
    }

    public function test_the_widgets_render(): void
    {
        $this->recordRun($this->mailbox, SyncRunStatus::Succeeded);
        $this->recordRun($this->mailbox, SyncRunStatus::Failed, ['error_type' => SyncErrorType::Connection, 'duration_ms' => 5000]);

        $statistics = app(HealthStatistics::class)->compute();
        $this->assertSame(50.0, $statistics['success_rate']);
        $this->assertSame(5000, $statistics['p95_ms']);
        $this->assertCount(24, $statistics['hourly_success']);
        $this->assertSame(1, HealthStatistics::count($statistics, [MailboxHealth::Degraded]));

        Livewire::test(MailboxHealthStatsWidget::class)
            ->assertSee(__('filament-mailbox::mailbox.health.kpis.success_rate'))
            ->assertSee('50 %');

        Cache::flush();

        Livewire::test(SystemChecksWidget::class)
            ->assertSee(__('filament-mailbox::mailbox.health.checks.queue.label'))
            ->assertSee(__('filament-mailbox::mailbox.health.checks.not_run'))
            ->callAction(TestAction::make('runChecks')->table())
            ->assertNotified();
    }
}
