<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

class MessagesUnsnoozed
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $messageIds
     * @param  bool  $woken  true when the snooze time was reached, false when a user cancelled it
     */
    public function __construct(
        public Mailbox $mailbox,
        public array $messageIds,
        public bool $woken = false,
    ) {}
}
