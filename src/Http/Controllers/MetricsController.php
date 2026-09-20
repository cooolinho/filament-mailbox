<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Prometheus text format. Disabled by default and protected by a token;
 * contains ids and counts only, no mailbox names or addresses.
 */
class MetricsController
{
    public const BUCKETS = [1, 5, 15, 30, 60, 120, 300];

    public function __invoke(Request $request): Response
    {
        $config = (array) config('filament-mailbox.monitoring.prometheus', []);
        $token = (string) ($config['token'] ?? '');

        abort_unless(($config['enabled'] ?? false) && config('filament-mailbox.monitoring.enabled', true), 404);
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401);

        $window = now()->subMinutes(max(1, (int) ($config['window_minutes'] ?? 15)));
        $runs = MailboxSyncRun::query()->where('started_at', '>=', $window)->get(['mailbox_id', 'status', 'duration_ms', 'error_type']);
        $lines = [];

        $lines[] = '# HELP filament_mailbox_sync_runs Sync runs started in the window, by status.';
        $lines[] = '# TYPE filament_mailbox_sync_runs gauge';

        foreach (SyncRunStatus::cases() as $status) {
            $lines[] = sprintf('filament_mailbox_sync_runs{status="%s"} %d', $status->value, $runs->where('status', $status)->count());
        }

        $finished = $runs->whereNotNull('duration_ms');
        $lines[] = '# HELP filament_mailbox_sync_duration_seconds Duration of finished sync runs in the window.';
        $lines[] = '# TYPE filament_mailbox_sync_duration_seconds histogram';

        foreach (self::BUCKETS as $bucket) {
            $lines[] = sprintf('filament_mailbox_sync_duration_seconds_bucket{le="%s"} %d', $bucket, $finished->filter(fn (MailboxSyncRun $run): bool => $run->duration_ms / 1000 <= $bucket)->count());
        }

        $lines[] = sprintf('filament_mailbox_sync_duration_seconds_bucket{le="+Inf"} %d', $finished->count());
        $lines[] = sprintf('filament_mailbox_sync_duration_seconds_sum %s', $this->number($finished->sum('duration_ms') / 1000));
        $lines[] = sprintf('filament_mailbox_sync_duration_seconds_count %d', $finished->count());

        $lines[] = '# HELP filament_mailbox_provider_errors Failed sync runs in the window, by error type.';
        $lines[] = '# TYPE filament_mailbox_provider_errors gauge';

        foreach ($runs->whereNotNull('error_type')->groupBy(fn (MailboxSyncRun $run): string => $run->error_type->value) as $type => $group) {
            $lines[] = sprintf('filament_mailbox_provider_errors{type="%s"} %d', $type, $group->count());
        }

        $lines[] = '# HELP filament_mailbox_open_alerts Open mailbox alerts.';
        $lines[] = '# TYPE filament_mailbox_open_alerts gauge';
        $lines[] = sprintf('filament_mailbox_open_alerts %d', MailboxAlert::query()->open()->count());

        $lines[] = '# HELP filament_mailbox_active_mailboxes Active mailboxes.';
        $lines[] = '# TYPE filament_mailbox_active_mailboxes gauge';
        $lines[] = sprintf('filament_mailbox_active_mailboxes %d', Mailbox::query()->active()->count());

        if ($config['mailbox_labels'] ?? false) {
            $lines[] = '# HELP filament_mailbox_last_success_timestamp Unix time of the last successful synchronisation.';
            $lines[] = '# TYPE filament_mailbox_last_success_timestamp gauge';

            Mailbox::query()->active()->whereNotNull('last_synced_at')->each(function (Mailbox $mailbox) use (&$lines): void {
                $lines[] = sprintf('filament_mailbox_last_success_timestamp{mailbox="%d"} %d', $mailbox->getKey(), $mailbox->last_synced_at->getTimestamp());
            });
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
