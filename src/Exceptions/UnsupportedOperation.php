<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use RuntimeException;

/**
 * Raised when a provider does not support an optional operation.
 */
class UnsupportedOperation extends RuntimeException
{
    public static function for(string $operation, ?ProviderCapability $capability = null): self
    {
        return new self($capability
            ? sprintf('The mailbox provider does not support "%s" (capability "%s").', $operation, $capability->value)
            : sprintf('The mailbox provider does not support "%s".', $operation));
    }
}
