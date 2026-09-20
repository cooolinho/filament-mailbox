<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use RuntimeException;

class SyncFailed extends RuntimeException
{
    /**
     * Retrying does not help, e.g. because an OAuth account must be reconnected.
     */
    public bool $permanent = false;

    /**
     * Class of the original exception (not chained, it may carry credentials).
     *
     * @var ?class-string
     */
    public ?string $cause = null;

    /**
     * @param  ?class-string  $cause
     */
    public static function because(string $reason, bool $permanent = false, ?string $cause = null): self
    {
        $exception = new self($reason);
        $exception->permanent = $permanent;
        $exception->cause = $cause;

        return $exception;
    }
}
