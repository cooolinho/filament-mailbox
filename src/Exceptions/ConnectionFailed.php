<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use RuntimeException;
use Throwable;

/**
 * Raised when the remote mailbox cannot be reached or rejects the login.
 *
 * The message never contains credentials, so it is safe to log and display.
 */
class ConnectionFailed extends RuntimeException
{
    public static function for(Mailbox $mailbox, Throwable $previous): self
    {
        $reason = CredentialRedactor::redact($previous->getMessage(), $mailbox);

        // The previous exception is intentionally not chained: its message and
        // trace arguments may contain the plain password.
        $hint = $mailbox->usesOAuth() ? ' Reconnect the OAuth account if the problem persists.' : '';

        $target = $mailbox->provider->usesServerSettings()
            ? sprintf('%s:%d', $mailbox->host, $mailbox->port)
            : $mailbox->provider->getLabel();

        return new self(sprintf('Connection to %s failed: %s', $target, $reason).$hint);
    }
}
