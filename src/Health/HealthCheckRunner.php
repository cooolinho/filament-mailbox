<?php

namespace Cooolinho\FilamentMailbox\Health;

use Cooolinho\FilamentMailbox\Enums\HealthCheckStatus;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the configured system checks. Expensive checks are run by
 * "mailbox:monitor" and cached; the dashboard only reads the cache.
 */
class HealthCheckRunner
{
    public const CACHE_KEY = 'filament-mailbox:health:checks';

    /**
     * @return array<string, HealthCheck>
     */
    public function checks(): array
    {
        $checks = [];

        foreach ((array) config('filament-mailbox.health.checks', []) as $class) {
            $check = app($class);

            if ($check instanceof HealthCheck) {
                $checks[$check->key()] = $check;
            }
        }

        return $checks;
    }

    /**
     * Run every check and cache the results of the expensive ones.
     *
     * @return array<string, HealthCheckResult>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks() as $key => $check) {
            $results[$key] = $this->runCheck($check);
        }

        $cached = array_map(
            fn (HealthCheckResult $result): array => $result->toArray(),
            array_filter($results, fn (HealthCheckResult $result, string $key): bool => ! $this->checks()[$key]->isLive(), ARRAY_FILTER_USE_BOTH),
        );

        Cache::put(self::CACHE_KEY, $cached, now()->addDay());

        return $results;
    }

    /**
     * Cached results of expensive checks and fresh results of live checks.
     *
     * @return array<string, HealthCheckResult>
     */
    public function results(): array
    {
        $cached = (array) Cache::get(self::CACHE_KEY, []);
        $results = [];

        foreach ($this->checks() as $key => $check) {
            $results[$key] = match (true) {
                $check->isLive() => $this->runCheck($check),
                isset($cached[$key]) && is_array($cached[$key]) => HealthCheckResult::fromArray($cached[$key]),
                default => new HealthCheckResult(HealthCheckStatus::Warning, __('filament-mailbox::mailbox.health.checks.not_run')),
            };
        }

        return $results;
    }

    /**
     * @param  array<string, HealthCheckResult>  $results
     */
    public function worstStatus(array $results): HealthCheckStatus
    {
        foreach ([HealthCheckStatus::Failed, HealthCheckStatus::Warning] as $status) {
            foreach ($results as $result) {
                if ($result->status === $status) {
                    return $status;
                }
            }
        }

        return HealthCheckStatus::Ok;
    }

    protected function runCheck(HealthCheck $check): HealthCheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $exception) {
            report($exception);

            // No exception message: it may contain credentials or paths.
            return HealthCheckResult::failed(__('filament-mailbox::mailbox.health.checks.exception', ['error' => class_basename($exception)]));
        }
    }
}
