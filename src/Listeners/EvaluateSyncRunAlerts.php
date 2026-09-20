<?php

namespace Cooolinho\FilamentMailbox\Listeners;

use Cooolinho\FilamentMailbox\Events\SyncRunFinished;
use Cooolinho\FilamentMailbox\Monitoring\AlertEvaluator;
use Throwable;

class EvaluateSyncRunAlerts
{
    public function __construct(
        protected AlertEvaluator $alerts,
    ) {}

    public function handle(SyncRunFinished $event): void
    {
        try {
            $this->alerts->afterRun($event->run);
        } catch (Throwable $exception) {
            // Alerting must never break a synchronisation.
            report($exception);
        }
    }
}
