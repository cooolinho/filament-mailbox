<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

/**
 * "mailbox:monitor" writes the heartbeat on every run.
 */
class SchedulerHeartbeatCheck extends HeartbeatCheck
{
    public static function cacheKey(): string
    {
        return 'filament-mailbox:health:scheduler-heartbeat';
    }

    public function key(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return __('filament-mailbox::mailbox.health.checks.scheduler.label');
    }

    protected function thresholds(): array
    {
        return (array) config('filament-mailbox.health.scheduler_heartbeat', ['warning_after' => 10, 'failed_after' => 20]) + ['warning_after' => 10, 'failed_after' => 20];
    }
}
