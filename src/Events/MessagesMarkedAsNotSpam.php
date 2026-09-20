<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

class MessagesMarkedAsNotSpam
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
