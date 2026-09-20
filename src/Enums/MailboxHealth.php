<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum MailboxHealth: string implements HasColor, HasIcon, HasLabel
{
    /** The OAuth account must be reconnected or the server rejects the login. */
    case Reconnect = 'reconnect';

    /** Repeated failures or an open critical alert. */
    case Failing = 'failing';

    /** The last run was partial or failed once or twice. */
    case Degraded = 'degraded';

    /** No successful synchronisation for too long. */
    case Stale = 'stale';

    /** Never synchronised. */
    case Unknown = 'unknown';

    case Healthy = 'healthy';

    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.health.statuses.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reconnect, self::Failing => 'danger',
            self::Degraded, self::Stale => 'warning',
            self::Healthy => 'success',
            self::Unknown => 'info',
            self::Inactive => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Reconnect => Heroicon::OutlinedKey,
            self::Failing => Heroicon::OutlinedXCircle,
            self::Degraded, self::Stale => Heroicon::OutlinedExclamationTriangle,
            self::Healthy => Heroicon::OutlinedCheckCircle,
            self::Unknown => Heroicon::OutlinedQuestionMarkCircle,
            self::Inactive => Heroicon::OutlinedPauseCircle,
        };
    }

    /**
     * Lower is worse; used to sort the dashboard.
     */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    public function needsAttention(): bool
    {
        return $this === self::Reconnect || $this === self::Failing;
    }

    public function isWarning(): bool
    {
        return $this === self::Degraded || $this === self::Stale;
    }
}
