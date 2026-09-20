<?php

namespace Cooolinho\FilamentMailbox\Health;

use Cooolinho\FilamentMailbox\Enums\MailboxHealth;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Key figures of the health dashboard, from the denormalised mailbox fields
 * and the sync runs of the last 24 hours. Cached briefly, because many open
 * dashboards poll them.
 */
class HealthStatistics
{
    public const CACHE_KEY = 'filament-mailbox:health:statistics';

    /**
     * @return array{active: int, by_health: array<string, int>, open_alerts: int, success_rate: ?float, p95_ms: ?int, hourly_success: array<int, float>, queue_size: ?int}
     */
    public function get(): array
    {
        $seconds = max(0, (int) config('filament-mailbox.health.statistics_cache_seconds', 30));

        return $seconds === 0
            ? $this->compute()
            : Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->compute());
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{active: int, by_health: array<string, int>, open_alerts: int, success_rate: ?float, p95_ms: ?int, hourly_success: array<int, float>, queue_size: ?int}
     */
    public function compute(): array
    {
        $byHealth = Mailbox::query()
            ->selectRaw('health, count(*) as aggregate')
            ->groupBy('health')
            ->pluck('aggregate', 'health')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $since = now()->subDay();
        $finished = MailboxSyncRun::query()->where('started_at', '>=', $since)->where('status', '!=', SyncRunStatus::Running);

        $total = (clone $finished)->count();
        $successful = (clone $finished)->whereIn('status', [SyncRunStatus::Succeeded, SyncRunStatus::Partial])->count();

        return [
            'active' => Mailbox::query()->active()->count(),
            'by_health' => $byHealth,
            'open_alerts' => MailboxAlert::query()->open()->count(),
            'success_rate' => $total > 0 ? round($successful / $total * 100, 1) : null,
            'p95_ms' => $this->percentile(clone $finished, 95, $total),
            'hourly_success' => $this->hourlySuccess($since),
            'queue_size' => $this->queueSize(),
        ];
    }

    /**
     * @param  array{by_health: array<string, int>}  $statistics
     * @param  array<int, MailboxHealth>  $statuses
     */
    public static function count(array $statistics, array $statuses): int
    {
        return array_sum(array_map(fn (MailboxHealth $health): int => $statistics['by_health'][$health->value] ?? 0, $statuses));
    }

    protected function percentile(mixed $query, int $percentile, int $total): ?int
    {
        $durations = $query->whereNotNull('duration_ms');
        $count = $total === 0 ? 0 : (clone $durations)->count();

        if ($count === 0) {
            return null;
        }

        // Nearest-rank method without loading all durations.
        $offset = max(0, (int) ceil($percentile / 100 * $count) - 1);

        return (int) $durations->orderBy('duration_ms')->offset($offset)->limit(1)->value('duration_ms');
    }

    /**
     * Success rate per hour (oldest first), 100 for hours without runs.
     *
     * @return array<int, float>
     */
    protected function hourlySuccess(mixed $since): array
    {
        $runs = MailboxSyncRun::query()
            ->where('started_at', '>=', $since)
            ->where('status', '!=', SyncRunStatus::Running)
            ->toBase()
            ->get(['started_at', 'status']);

        $hours = [];

        for ($hour = 23; $hour >= 0; $hour--) {
            $hours[now()->subHours($hour)->format('Y-m-d H')] = ['total' => 0, 'successful' => 0];
        }

        foreach ($runs as $run) {
            $key = \Illuminate\Support\Carbon::parse($run->started_at)->format('Y-m-d H');

            if (! isset($hours[$key])) {
                continue;
            }

            $hours[$key]['total']++;
            $hours[$key]['successful'] += SyncRunStatus::from($run->status)->isSuccessful() ? 1 : 0;
        }

        return array_values(array_map(
            fn (array $hour): float => $hour['total'] === 0 ? 100.0 : round($hour['successful'] / $hour['total'] * 100, 1),
            $hours,
        ));
    }

    protected function queueSize(): ?int
    {
        try {
            return Queue::connection(config('filament-mailbox.sync.queue_connection'))->size(config('filament-mailbox.sync.queue'));
        } catch (Throwable) {
            return null;
        }
    }
}
