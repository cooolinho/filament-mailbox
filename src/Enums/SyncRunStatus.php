<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SyncRunStatus: string implements HasColor, HasLabel
{
    case Running = 'running';

    case Succeeded = 'succeeded';

    /** Some folders failed. */
    case Partial = 'partial';

    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.monitoring.statuses.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
        };
    }

    /**
     * The mailbox could be reached.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded || $this === self::Partial;
    }
}
