<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use Cooolinho\FilamentMailbox\Models\OAuthConnection;

/**
 * The refresh token was revoked or expired: the account must be connected again.
 * Retrying does not help.
 */
class OAuthReconnectRequired extends ConnectionFailed
{
    public static function forConnection(OAuthConnection $connection): self
    {
        return new self(sprintf(
            'The OAuth connection for %s is no longer valid. Reconnect the account.',
            $connection->account_email ?? '#'.$connection->getKey(),
        ));
    }
}
