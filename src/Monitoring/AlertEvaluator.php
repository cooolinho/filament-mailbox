<?php

namespace Cooolinho\FilamentMailbox\Monitoring;

use Cooolinho\FilamentMailbox\Enums\AlertRule;
use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;

/**
 * Opens, deduplicates and resolves mailbox alerts.
 */
class AlertEvaluator
{
    public function __construct(
        protected AlertNotifier $notifier,
    ) {}

    /**
     * Authentication errors alert immediately, a success resolves the alerts
     * of the mailbox.
     */
    public function afterRun(MailboxSyncRun $run): void
    {
        $mailbox = $run->mailbox;

        if (! $mailbox) {
            return;
        }

        if ($run->status->isSuccessful()) {
            foreach (AlertRule::cases() as $rule) {
                $this->resolve($mailbox, $rule);
            }

            return;
        }

        if ($run->status !== SyncRunStatus::Failed) {
            return;
        }

        if ($run->error_type === SyncErrorType::Authentication) {
            $this->open($mailbox, AlertRule::Authentication, __('filament-mailbox::mailbox.monitoring.messages.authentication', [
                'mailbox' => $mailbox->name,
                'error' => (string) $run->error,
            ]));
        }

        $this->evaluateConsecutiveFailures($mailbox);
    }

    /**
     * Periodic evaluation (mailbox:monitor).
     */
    public function evaluate(Mailbox $mailbox): void
    {
        if (! $mailbox->is_active) {
            // Nobody expects an inactive mailbox to synchronise.
            $this->resolve($mailbox, AlertRule::Stale);
            $this->resolve($mailbox, AlertRule::ConsecutiveFailures);

            return;
        }

        $this->evaluateConsecutiveFailures($mailbox);
        $this->evaluateStale($mailbox);
    }

    public function evaluateConsecutiveFailures(Mailbox $mailbox): void
    {
        $threshold = max(1, (int) config('filament-mailbox.monitoring.alerts.consecutive_failures', 3));

        $recent = $mailbox->syncRuns()
            ->where('status', '!=', SyncRunStatus::Running)
            ->latest('started_at')
            ->latest('id')
            ->limit($threshold)
            ->pluck('status');

        if ($recent->count() < $threshold) {
            return;
        }

        if ($recent->every(fn (SyncRunStatus $status): bool => $status === SyncRunStatus::Failed)) {
            $this->open($mailbox, AlertRule::ConsecutiveFailures, __('filament-mailbox::mailbox.monitoring.messages.consecutive_failures', [
                'mailbox' => $mailbox->name,
                'count' => $threshold,
            ]));
        } elseif ($recent->first()->isSuccessful()) {
            $this->resolve($mailbox, AlertRule::ConsecutiveFailures);
        }
    }

    public function evaluateStale(Mailbox $mailbox): void
    {
        $minutes = max(1, (int) config('filament-mailbox.monitoring.expected_sync_interval_minutes', 5))
            * max(1, (int) config('filament-mailbox.monitoring.alerts.stale_factor', 3));

        $lastSuccess = $mailbox->last_synced_at ?? $mailbox->created_at;

        if ($lastSuccess && $lastSuccess->lt(now()->subMinutes($minutes))) {
            $this->open($mailbox, AlertRule::Stale, __('filament-mailbox::mailbox.monitoring.messages.stale', [
                'mailbox' => $mailbox->name,
                'since' => $lastSuccess->toDateTimeString(),
            ]));
        } else {
            $this->resolve($mailbox, AlertRule::Stale);
        }
    }

    /**
     * Runs still "running" after the timeout were killed (worker crash, job timeout).
     */
    public function closeHangingRuns(): int
    {
        $minutes = max(1, (int) config('filament-mailbox.monitoring.run_timeout_minutes', 60));
        $closed = 0;

        MailboxSyncRun::query()
            ->where('status', SyncRunStatus::Running)
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->each(function (MailboxSyncRun $run) use (&$closed): void {
                $run->forceFill([
                    'status' => SyncRunStatus::Failed,
                    'finished_at' => now(),
                    'duration_ms' => (int) $run->started_at->diffInMilliseconds(now()),
                    'error_type' => SyncErrorType::Timeout,
                    'error' => 'The run did not finish in time.',
                ])->save();

                $closed++;
            });

        return $closed;
    }

    /**
     * Open an alert unless the same rule is already open for the mailbox.
     */
    public function open(?Mailbox $mailbox, AlertRule $rule, string $message): MailboxAlert
    {
        $existing = MailboxAlert::query()
            ->open()
            ->where('rule', $rule)
            ->where('mailbox_id', $mailbox?->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        $alert = MailboxAlert::query()->create([
            'mailbox_id' => $mailbox?->getKey(),
            'rule' => $rule,
            'severity' => $rule->severity(),
            'message' => mb_substr($message, 0, 2000),
            'opened_at' => now(),
        ]);

        $this->notifier->opened($alert);

        return $alert;
    }

    public function resolve(?Mailbox $mailbox, AlertRule $rule, mixed $acknowledgedBy = null): void
    {
        MailboxAlert::query()
            ->open()
            ->where('rule', $rule)
            ->where('mailbox_id', $mailbox?->getKey())
            ->get()
            ->each(fn (MailboxAlert $alert) => $this->close($alert, $acknowledgedBy));
    }

    public function close(MailboxAlert $alert, mixed $acknowledgedBy = null): void
    {
        if (! $alert->isOpen()) {
            return;
        }

        $alert->forceFill(['resolved_at' => now(), 'acknowledged_by' => $acknowledgedBy])->save();

        // Manually acknowledged alerts need no "resolved" message.
        if ($acknowledgedBy === null) {
            $this->notifier->resolved($alert);
        }
    }
}
