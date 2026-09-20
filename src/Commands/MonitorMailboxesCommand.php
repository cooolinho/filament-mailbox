<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Health\Checks\SchedulerHeartbeatCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckRunner;
use Cooolinho\FilamentMailbox\Health\MailboxHealthEvaluator;
use Cooolinho\FilamentMailbox\Jobs\MailboxHeartbeatJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Monitoring\AlertEvaluator;
use Illuminate\Console\Command;
use Throwable;

class MonitorMailboxesCommand extends Command
{
    protected $signature = 'mailbox:monitor';

    protected $description = 'Close hanging sync runs, evaluate mailbox alert rules, rate mailbox health and run the system checks';

    public function handle(AlertEvaluator $alerts, MailboxHealthEvaluator $health, HealthCheckRunner $checks): int
    {
        if (! config('filament-mailbox.monitoring.enabled', true)) {
            $this->components->warn('Mailbox monitoring is disabled.');

            return self::SUCCESS;
        }

        $closed = $alerts->closeHangingRuns();

        Mailbox::query()->with('oauthConnection')->each(function (Mailbox $mailbox) use ($alerts, $health): void {
            $alerts->evaluate($mailbox);
            $health->refresh($mailbox);
        });

        $this->components->info("Evaluated mailbox alerts, closed {$closed} hanging sync runs.");

        if (config('filament-mailbox.health.enabled', true)) {
            $this->checkHealth($checks);
        }

        return self::SUCCESS;
    }

    protected function checkHealth(HealthCheckRunner $checks): void
    {
        SchedulerHeartbeatCheck::beat();

        try {
            MailboxHeartbeatJob::dispatch();
        } catch (Throwable $exception) {
            // The queue check reports the missing heartbeat.
            report($exception);
        }

        foreach ($checks->run() as $key => $result) {
            $this->components->twoColumnDetail($key, $result->status->value);
        }
    }
}
