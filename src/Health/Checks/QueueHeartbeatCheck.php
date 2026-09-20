<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

/**
 * "mailbox:monitor" dispatches MailboxHeartbeatJob on the sync queue; a worker
 * processing it writes the heartbeat.
 */
class QueueHeartbeatCheck extends HeartbeatCheck
{
    public static function cacheKey(): string
    {
        return 'filament-mailbox:health:queue-heartbeat';
    }

    public function key(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return __('filament-mailbox::mailbox.health.checks.queue.label');
    }

    protected function thresholds(): array
    {
        return (array) config('filament-mailbox.health.queue_heartbeat', ['warning_after' => 10, 'failed_after' => 20]) + ['warning_after' => 10, 'failed_after' => 20];
    }
}
