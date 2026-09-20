<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum OutgoingStatus: string implements HasColor, HasIcon, HasLabel
{
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.outbox.statuses.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Sending => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Scheduled => Heroicon::OutlinedClock,
            self::Sending => Heroicon::OutlinedPaperAirplane,
            self::Sent => Heroicon::OutlinedCheckCircle,
            self::Failed => Heroicon::OutlinedExclamationTriangle,
            self::Cancelled => Heroicon::OutlinedXCircle,
        };
    }

    /**
     * Messages that can still be changed, sent now or cancelled.
     */
    public function isPending(): bool
    {
        return $this === self::Scheduled;
    }
}
