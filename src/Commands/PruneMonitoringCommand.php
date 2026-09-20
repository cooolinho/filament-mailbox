<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Illuminate\Console\Command;

class PruneMonitoringCommand extends Command
{
    protected $signature = 'mailbox:prune-monitoring
        {--days= : Keep this many days (default: monitoring.retention_days)}';

    protected $description = 'Delete old sync runs and resolved alerts';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('filament-mailbox.monitoring.retention_days', 30)));
        $before = now()->subDays($days);

        $runs = MailboxSyncRun::query()->where('started_at', '<', $before)->delete();
        $alerts = MailboxAlert::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $before)->delete();

        $this->components->info("Deleted {$runs} sync runs and {$alerts} resolved alerts older than {$days} days.");

        return self::SUCCESS;
    }
}
