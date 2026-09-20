<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use RuntimeException;

/**
 * A folder operation was rejected before it reached the server, e.g. an
 * invalid name or a protected system folder. The message is translated.
 */
class InvalidFolderOperation extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $reason, array $replace = []): self
    {
        return new self(__("filament-mailbox::mailbox.folders.errors.{$reason}", $replace));
    }
}
