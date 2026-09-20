<?php

namespace Cooolinho\FilamentMailbox\Support;

use Cooolinho\FilamentMailbox\Models\Mailbox;

final class CredentialRedactor
{
    public const MASK = '********';

    public static function redact(string $text, Mailbox $mailbox): string
    {
        $connection = $mailbox->usesOAuth() ? $mailbox->oauthConnection : null;

        $secrets = array_filter(
            [$mailbox->password, $connection?->access_token, $connection?->refresh_token],
            fn (?string $secret): bool => filled($secret),
        );

        $text = $secrets === [] ? $text : str_replace($secrets, self::MASK, $text);

        // Bearer tokens and XOAUTH2 strings of any origin.
        return (string) preg_replace(
            ['/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', '/auth=Bearer\s*[^\x01\s]+/i'],
            ['Bearer '.self::MASK, 'auth=Bearer '.self::MASK],
            $text,
        );
    }
}
