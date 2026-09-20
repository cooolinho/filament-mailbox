<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OAuthConnectionStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case NeedsReconnect = 'needs_reconnect';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.oauth.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::NeedsReconnect => 'warning',
            self::Revoked => 'danger',
        };
    }
}
