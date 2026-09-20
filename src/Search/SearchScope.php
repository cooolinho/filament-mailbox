<?php

namespace Cooolinho\FilamentMailbox\Search;

/**
 * Where to search: always one mailbox, optionally one folder. Enforced by every engine.
 */
final readonly class SearchScope
{
    public function __construct(
        public int $mailboxId,
        public ?int $folderId = null,
    ) {}
}
