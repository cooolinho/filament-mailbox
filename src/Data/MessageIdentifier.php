<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class MessageIdentifier
{
    /**
     * @param  string  $remoteId  IMAP: "<uidvalidity>:<uid>", Graph: immutable id, Gmail: message id, POP3: UIDL
     */
    public function __construct(
        public FolderIdentifier $folder,
        public string $remoteId,
    ) {}
}
