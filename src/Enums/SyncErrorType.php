<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

enum SyncErrorType: string implements HasLabel
{
    case Connection = 'connection';
    case Authentication = 'authentication';
    case Timeout = 'timeout';
    case Protocol = 'protocol';
    case RateLimited = 'rate_limited';
    case Storage = 'storage';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.monitoring.error_types.'.$this->value);
    }
}
