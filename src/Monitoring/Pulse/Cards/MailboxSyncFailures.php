<?php

namespace Cooolinho\FilamentMailbox\Monitoring\Pulse\Cards;

use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\RecordSyncRunInPulse;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Pulse card: failed and partial synchronisation runs by error type.
 *
 * <livewire:filament-mailbox.pulse.sync-failures cols="6" />
 */
#[Lazy]
class MailboxSyncFailures extends Card
{
    public function render(): Renderable
    {
        [$failures, $time, $runAt] = $this->remember(
            fn () => $this->aggregate(RecordSyncRunInPulse::FAILURE, ['count'], 'count')
                ->map(fn ($row) => (object) [
                    'type' => SyncErrorType::tryFrom((string) $row->key)?->getLabel() ?? (string) $row->key,
                    'count' => (int) $row->count,
                ]),
        );

        return View::make('filament-mailbox::pulse.mailbox-sync-failures', [
            'time' => $time,
            'runAt' => $runAt,
            'failures' => $failures,
        ]);
    }
}
