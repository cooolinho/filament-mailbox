<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class FolderIdentifier
{
    /**
     * @param  string  $remoteId  IMAP: path, Graph: folder id, Gmail: label id, POP3: "INBOX"
     */
    public function __construct(
        public string $remoteId,
    ) {}

    public function is(FolderIdentifier $folder): bool
    {
        return $this->remoteId === $folder->remoteId;
    }
}
