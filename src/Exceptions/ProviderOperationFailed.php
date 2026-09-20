<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use RuntimeException;
use Throwable;

class ProviderOperationFailed extends RuntimeException
{
    public static function for(Mailbox $mailbox, string $operation, Throwable $previous): self
    {
        $reason = CredentialRedactor::redact($previous->getMessage(), $mailbox);

        return new self(sprintf('Mailbox operation "%s" failed: %s', $operation, $reason));
    }
}
