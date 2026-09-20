<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuthMode: string implements HasLabel
{
    case Password = 'password';
    case OAuth = 'oauth';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.auth_mode.'.$this->value);
    }
}
