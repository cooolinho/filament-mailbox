<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

enum AlertRule: string implements HasLabel
{
    /** Several synchronisation runs in a row failed. */
    case ConsecutiveFailures = 'consecutive_failures';

    /** No successful synchronisation for too long. */
    case Stale = 'stale';

    /** The server rejected the login or the OAuth account must be reconnected. */
    case Authentication = 'authentication';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.monitoring.rules.'.$this->value);
    }

    public function severity(): AlertSeverity
    {
        return $this === self::Stale ? AlertSeverity::Warning : AlertSeverity::Critical;
    }
}
