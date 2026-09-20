<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class FolderChanges
{
    /**
     * @param  array<int, MessageData>  $created
     * @param  array<string, MessageFlags>  $updated  Flags keyed by remote id
     * @param  array<int, string>  $deleted  Remote ids
     * @param  bool  $hasMore  Call changes() again with the returned cursor
     * @param  bool  $reset  Local messages of the folder are outdated (e.g. UIDVALIDITY changed, delta token expired)
     * @param  bool  $snapshot  $updated lists every message left in the folder; local messages missing in it were deleted
     * @param  bool  $includesNew  $updated may contain messages that were never synchronised; their content is
     *                             loaded with MailboxProvider::fetchMessages() (delta APIs that do not tell new from changed)
     */
    public function __construct(
        public array $created,
        public array $updated,
        public array $deleted,
        public SyncCursor $cursor,
        public bool $hasMore = false,
        public bool $reset = false,
        public bool $snapshot = false,
        public bool $includesNew = false,
    ) {}
}
