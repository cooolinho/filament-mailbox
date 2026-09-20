<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

use Cooolinho\FilamentMailbox\Health\HealthCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Failed jobs of this package in the last 24 hours (database failed job provider).
 */
class FailedJobsCheck implements HealthCheck
{
    public function key(): string
    {
        return 'failed_jobs';
    }

    public function label(): string
    {
        return __('filament-mailbox::mailbox.health.checks.failed_jobs.label');
    }

    public function isLive(): bool
    {
        return false;
    }

    public function run(): HealthCheckResult
    {
        $connection = config('queue.failed.database') ?: config('database.default');
        $table = (string) config('queue.failed.table', 'failed_jobs');

        if (! Schema::connection($connection)->hasTable($table)) {
            return HealthCheckResult::skipped(__('filament-mailbox::mailbox.health.checks.failed_jobs.no_table'));
        }

        $count = DB::connection($connection)->table($table)
            ->where('failed_at', '>=', now()->subDay())
            // Class names are JSON-escaped in the payload ("Cooolinho\\FilamentMailbox\\...").
            ->where('payload', 'like', '%FilamentMailbox%')
            ->count();

        return $count > 0
            ? HealthCheckResult::warning(trans_choice('filament-mailbox::mailbox.health.checks.failed_jobs.found', $count, ['count' => $count]), ['count' => $count])
            : HealthCheckResult::ok(__('filament-mailbox::mailbox.health.checks.failed_jobs.none'));
    }
}
