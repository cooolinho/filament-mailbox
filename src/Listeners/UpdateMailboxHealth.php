<?php

namespace Cooolinho\FilamentMailbox\Listeners;

use Cooolinho\FilamentMailbox\Events\SyncRunFinished;
use Cooolinho\FilamentMailbox\Health\MailboxHealthEvaluator;
use Throwable;

class UpdateMailboxHealth
{
    public function __construct(
        protected MailboxHealthEvaluator $health,
    ) {}

    public function handle(SyncRunFinished $event): void
    {
        try {
            $this->health->recordRun($event->run);
        } catch (Throwable $exception) {
            // The health rating must never break a synchronisation.
            report($exception);
        }
    }
}
