<?php

namespace Cooolinho\FilamentMailbox\Monitoring\Pulse\Cards;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\RecordSyncRunInPulse;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;

/**
 * Pulse card: synchronisation runs per mailbox with average and slowest duration.
 *
 * <livewire:filament-mailbox.pulse.syncs cols="6" />
 */
#[Lazy]
class MailboxSyncs extends Card
{
    /** @var 'slowest'|'average'|'count' */
    #[Url(as: 'mailbox-syncs')]
    public string $orderBy = 'slowest';

    public function render(): Renderable
    {
        [$syncs, $time, $runAt] = $this->remember(
            function () {
                $rows = $this->aggregate(RecordSyncRunInPulse::SYNC, ['avg', 'max', 'count'], match ($this->orderBy) {
                    'average' => 'avg',
                    'count' => 'count',
                    default => 'max',
                });

                $names = Mailbox::query()->whereKey($rows->pluck('key'))->pluck('name', 'id');

                return $rows->map(fn ($row) => (object) [
                    'mailbox' => $names[(int) $row->key] ?? '#'.$row->key,
                    'count' => (int) $row->count,
                    'average' => $row->avg === null ? null : (int) round($row->avg),
                    'slowest' => $row->max === null ? null : (int) $row->max,
                ]);
            },
            $this->orderBy,
        );

        return View::make('filament-mailbox::pulse.mailbox-syncs', [
            'time' => $time,
            'runAt' => $runAt,
            'syncs' => $syncs,
        ]);
    }
}
