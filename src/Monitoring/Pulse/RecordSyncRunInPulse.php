<?php

namespace Cooolinho\FilamentMailbox\Monitoring\Pulse;

use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Events\SyncRunFinished;
use Laravel\Pulse\Facades\Pulse;

/**
 * Feeds finished sync runs into Laravel Pulse:
 * "mailbox_sync" (key: mailbox id, value: duration in ms, avg/max/count) and
 * "mailbox_sync_failure" (key: error type, count).
 */
class RecordSyncRunInPulse
{
    public const SYNC = 'mailbox_sync';

    public const FAILURE = 'mailbox_sync_failure';

    public function handle(SyncRunFinished $event): void
    {
        $run = $event->run;
        $timestamp = $run->finished_at ?? now();

        Pulse::record(self::SYNC, (string) $run->mailbox_id, (int) $run->duration_ms, $timestamp)
            ->avg()
            ->max()
            ->count();

        if ($run->status !== SyncRunStatus::Succeeded && $run->error_type) {
            Pulse::record(self::FAILURE, $run->error_type->value, null, $timestamp)->count();
        }
    }
}
