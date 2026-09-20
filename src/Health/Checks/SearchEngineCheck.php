<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

use Cooolinho\FilamentMailbox\Health\HealthCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchSearchEngine;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Throwable;

/**
 * Runs a search with the configured engine and measures its latency.
 */
class SearchEngineCheck implements HealthCheck
{
    public function key(): string
    {
        return 'search';
    }

    public function label(): string
    {
        return __('filament-mailbox::mailbox.health.checks.search.label');
    }

    public function isLive(): bool
    {
        return false;
    }

    public function run(): HealthCheckResult
    {
        $started = hrtime(true);

        $search = app(SearchManager::class);

        try {
            // A fallback would hide an unreachable instance.
            if (($engine = $search->engine()) instanceof MeilisearchSearchEngine) {
                $engine->ping();
            }

            $search->engine()->search($search->parse('health'), new SearchScope((int) (MailboxMessage::query()->value('mailbox_id') ?? 0)), 1);
        } catch (Throwable $exception) {
            return HealthCheckResult::failed(__('filament-mailbox::mailbox.health.checks.search.error', ['error' => class_basename($exception)]));
        }

        $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);
        $message = __('filament-mailbox::mailbox.health.checks.search.ok', ['ms' => $milliseconds, 'engine' => $search->engine()->name()]);

        return $milliseconds > (int) config('filament-mailbox.health.search_warning_ms', 2000)
            ? HealthCheckResult::warning($message, ['latency_ms' => $milliseconds])
            : HealthCheckResult::ok($message, ['latency_ms' => $milliseconds]);
    }
}
