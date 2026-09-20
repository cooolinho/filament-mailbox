<?php

namespace Cooolinho\FilamentMailbox\Data;

/**
 * Changes of a provider whose change feed spans the whole mailbox (Gmail history).
 */
final readonly class MailboxChanges
{
    /**
     * @param  array<string, array{folder: string, flags: MessageFlags}>  $messages  Current folder (remote id) and flags of new or changed messages, keyed by message remote id
     * @param  array<int, string>  $deleted  Message remote ids
     * @param  bool  $reset  The cursor expired and a limited resync started; local messages are matched, not deleted
     */
    public function __construct(
        public array $messages,
        public array $deleted,
        public SyncCursor $cursor,
        public bool $hasMore = false,
        public bool $reset = false,
    ) {}
}
