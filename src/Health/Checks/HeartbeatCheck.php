<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

use Cooolinho\FilamentMailbox\Health\HealthCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Compares the age of a heartbeat timestamp in the cache with thresholds in minutes.
 */
abstract class HeartbeatCheck implements HealthCheck
{
    abstract public static function cacheKey(): string;

    /**
     * @return array{warning_after: int, failed_after: int}
     */
    abstract protected function thresholds(): array;

    public static function beat(): void
    {
        // Longer than any sensible threshold, so a missing value means "never".
        Cache::put(static::cacheKey(), now()->getTimestamp(), now()->addDay());
    }

    public static function lastBeat(): ?Carbon
    {
        $timestamp = Cache::get(static::cacheKey());

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    public function isLive(): bool
    {
        return true;
    }

    public function run(): HealthCheckResult
    {
        $last = static::lastBeat();

        if ($last === null) {
            return HealthCheckResult::failed(__('filament-mailbox::mailbox.health.checks.'.$this->key().'.missing'));
        }

        $thresholds = $this->thresholds();
        $minutes = (int) floor($last->diffInSeconds(now(), true) / 60);
        $message = __('filament-mailbox::mailbox.health.checks.last_heartbeat', ['time' => $last->diffForHumans()]);
        $meta = ['last_heartbeat' => $last->toIso8601String()];

        return match (true) {
            $minutes >= max(1, (int) $thresholds['failed_after']) => HealthCheckResult::failed($message, $meta),
            $minutes >= max(1, (int) $thresholds['warning_after']) => HealthCheckResult::warning($message, $meta),
            default => HealthCheckResult::ok($message, $meta),
        };
    }
}
