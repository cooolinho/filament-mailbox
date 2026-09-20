<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Health\HealthCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Illuminate\Database\Eloquent\Builder;

/**
 * OAuth connections of active mailboxes that must be reconnected or were revoked.
 */
class OAuthConnectionsCheck implements HealthCheck
{
    public function key(): string
    {
        return 'oauth';
    }

    public function label(): string
    {
        return __('filament-mailbox::mailbox.health.checks.oauth.label');
    }

    public function isLive(): bool
    {
        return false;
    }

    public function run(): HealthCheckResult
    {
        $used = OAuthConnection::query()->whereHas('mailboxes', fn (Builder $query) => $query->active());

        $total = (clone $used)->count();

        if ($total === 0) {
            return HealthCheckResult::skipped(__('filament-mailbox::mailbox.health.checks.oauth.none'));
        }

        $broken = (clone $used)->where('status', '!=', OAuthConnectionStatus::Active)->count();

        return $broken > 0
            ? HealthCheckResult::failed(trans_choice('filament-mailbox::mailbox.health.checks.oauth.broken', $broken, ['count' => $broken]), ['broken' => $broken, 'total' => $total])
            : HealthCheckResult::ok(trans_choice('filament-mailbox::mailbox.health.checks.oauth.ok', $total, ['count' => $total]), ['total' => $total]);
    }
}
