<?php

namespace Cooolinho\FilamentMailbox\Events;

use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

class MessagesSnoozed
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public Mailbox $mailbox,
        public array $messageIds,
        public CarbonInterface $until,
    ) {}
}
