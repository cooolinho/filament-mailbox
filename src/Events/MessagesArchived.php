<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

class MessagesArchived
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public Mailbox $mailbox,
        public array $messageIds,
    ) {}
}
