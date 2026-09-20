<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

enum OAuthProviderType: string implements HasLabel
{
    case Microsoft = 'microsoft';
    case Google = 'google';

    public function getLabel(): string
    {
        return match ($this) {
            self::Microsoft => 'Microsoft',
            self::Google => 'Google',
        };
    }
}
