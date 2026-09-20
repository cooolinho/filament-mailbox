<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

enum Encryption: string implements HasLabel
{
    case Ssl = 'ssl';
    case Tls = 'tls';
    case StartTls = 'starttls';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ssl => 'SSL/TLS',
            self::Tls => 'TLS',
            self::StartTls => 'STARTTLS',
            self::None => __('filament-mailbox::mailbox.encryption.none'),
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::Ssl, self::Tls => 993,
            self::StartTls, self::None => 143,
        };
    }
}
