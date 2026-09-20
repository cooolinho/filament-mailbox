<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Foundation\Events\Dispatchable;

class MessagesImported
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $messageIds  ids of newly created messages (not restored ones)
     * @param  bool  $isInitial  first synchronisation of the folder or after a reset (e.g. UIDVALIDITY)
     */
    public function __construct(
        public MailboxFolder $folder,
        public int $count,
        public array $messageIds = [],
        public bool $isInitial = false,
    ) {}
}
