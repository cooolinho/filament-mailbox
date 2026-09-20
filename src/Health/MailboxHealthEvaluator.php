<?php

namespace Cooolinho\FilamentMailbox\Health;

use Cooolinho\FilamentMailbox\Enums\AlertSeverity;
use Cooolinho\FilamentMailbox\Enums\MailboxHealth;
use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Illuminate\Support\Carbon;

/**
 * Rates mailboxes from the denormalised run fields on the mailbox
 * (updated after every run), so rendering the dashboard needs no queries.
 */
class MailboxHealthEvaluator
{
    /**
     * @param  bool  $withAlerts  also consider open critical alerts (one query)
     */
    public function evaluate(Mailbox $mailbox, bool $withAlerts = true): MailboxHealth
    {
        $failures = (int) $mailbox->consecutive_failures;
        $lastStatus = $mailbox->last_run_status === null ? null : SyncRunStatus::tryFrom($mailbox->last_run_status);

        return match (true) {
            ! $mailbox->is_active => MailboxHealth::Inactive,
            $this->needsReconnect($mailbox, $lastStatus) => MailboxHealth::Reconnect,
            $failures >= $this->failureThreshold(),
            $withAlerts && $this->hasOpenCriticalAlert($mailbox) => MailboxHealth::Failing,
            $lastStatus === SyncRunStatus::Partial || $failures > 0 => MailboxHealth::Degraded,
            $this->lastSuccess($mailbox) === null => MailboxHealth::Unknown,
            $this->isStale($mailbox) => MailboxHealth::Stale,
            default => MailboxHealth::Healthy,
        };
    }

    /**
     * The status to display: the stored rating with the time-dependent rules
     * applied, without queries (the OAuth connection should be eager loaded).
     */
    public function current(Mailbox $mailbox): MailboxHealth
    {
        $health = $this->evaluate($mailbox, withAlerts: false);
        $stored = MailboxHealth::tryFrom((string) $mailbox->health);

        // "Failing" may come from an open alert, which only the full evaluation sees.
        if ($stored === MailboxHealth::Failing && $mailbox->is_active && $health->rank() > $stored->rank()) {
            return $stored;
        }

        return $health;
    }

    /**
     * Update the denormalised fields after a finished run and rate the mailbox again.
     */
    public function recordRun(MailboxSyncRun $run): ?MailboxHealth
    {
        if ($run->status === SyncRunStatus::Running) {
            return null;
        }

        $mailbox = Mailbox::query()->with('oauthConnection')->find($run->mailbox_id);

        if (! $mailbox) {
            return null;
        }

        $attributes = [
            'last_run_status' => $run->status->value,
            'last_run_duration_ms' => $run->duration_ms,
            'last_error_type' => $run->error_type?->value,
            'failing_folders' => collect($run->folder_stats ?? [])->filter(fn (array $stats): bool => ($stats['error_type'] ?? null) !== null)->count(),
        ];

        if ($run->status->isSuccessful()) {
            $attributes['consecutive_failures'] = 0;
            $attributes['last_success_at'] = $run->finished_at ?? now();
        } else {
            $attributes['consecutive_failures'] = min(65535, (int) $mailbox->consecutive_failures + 1);
        }

        $mailbox->forceFill($attributes);
        $mailbox->forceFill(['health' => $this->evaluate($mailbox)->value])->saveQuietly();

        return MailboxHealth::from($mailbox->health);
    }

    /**
     * Rate a mailbox again (periodically by mailbox:monitor) and store the result.
     */
    public function refresh(Mailbox $mailbox): MailboxHealth
    {
        $health = $this->evaluate($mailbox);

        if ($mailbox->health !== $health->value) {
            $mailbox->forceFill(['health' => $health->value])->saveQuietly();
        }

        return $health;
    }

    public function isStale(Mailbox $mailbox): bool
    {
        $lastSuccess = $this->lastSuccess($mailbox);

        return $lastSuccess !== null && $lastSuccess->lt(now()->subMinutes($this->staleAfterMinutes()));
    }

    public function lastSuccess(Mailbox $mailbox): ?Carbon
    {
        return $mailbox->last_success_at ?? $mailbox->last_synced_at;
    }

    public function staleAfterMinutes(): int
    {
        return max(1, (int) config('filament-mailbox.monitoring.expected_sync_interval_minutes', 5))
            * max(1, (int) config('filament-mailbox.monitoring.alerts.stale_factor', 3));
    }

    public function failureThreshold(): int
    {
        return max(1, (int) config('filament-mailbox.monitoring.alerts.consecutive_failures', 3));
    }

    protected function needsReconnect(Mailbox $mailbox, ?SyncRunStatus $lastStatus): bool
    {
        if ($mailbox->usesOAuth() && $mailbox->oauthConnection && ! $mailbox->oauthConnection->isActive()) {
            return true;
        }

        return $lastStatus === SyncRunStatus::Failed && $mailbox->last_error_type === SyncErrorType::Authentication->value;
    }

    protected function hasOpenCriticalAlert(Mailbox $mailbox): bool
    {
        return $mailbox->alerts()->open()->where('severity', AlertSeverity::Critical)->exists();
    }
}
