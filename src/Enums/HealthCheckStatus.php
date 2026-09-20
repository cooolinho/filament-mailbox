<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum HealthCheckStatus: string implements HasColor, HasIcon, HasLabel
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
    /** Not applicable in this installation (e.g. no search engine configured). */
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.health.check_statuses.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warning => 'warning',
            self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Ok => Heroicon::OutlinedCheckCircle,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
            self::Failed => Heroicon::OutlinedXCircle,
            self::Skipped => Heroicon::OutlinedMinusCircle,
        };
    }
}
