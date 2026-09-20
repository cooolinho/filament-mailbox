<?php

namespace Cooolinho\FilamentMailbox\Data;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;

final readonly class FolderData
{
    /**
     * Stable identifier on the server; defaults to the full name (IMAP path).
     */
    public string $remoteId;

    public function __construct(
        public string $name,
        public string $fullName,
        public ?string $delimiter = null,
        public ?SpecialUse $specialUse = null,
        public ?string $parentRemoteId = null,
        ?string $remoteId = null,
    ) {
        $this->remoteId = $remoteId ?? $fullName;
    }

    public function identifier(): FolderIdentifier
    {
        return new FolderIdentifier($this->remoteId);
    }
}
