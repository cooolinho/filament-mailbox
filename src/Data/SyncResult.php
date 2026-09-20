<?php

namespace Cooolinho\FilamentMailbox\Data;

final class SyncResult
{
    public int $folders = 0;

    public int $imported = 0;

    /** Messages whose flags or folder changed. */
    public int $updated = 0;

    /** Messages deleted on the server. */
    public int $deleted = 0;

    /** @var array<string, string> Error messages keyed by folder name */
    public array $errors = [];

    public function successful(): bool
    {
        return $this->errors === [];
    }
}
