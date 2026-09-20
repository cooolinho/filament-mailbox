<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Labels of messages changed, locally or through synchronisation.
 */
class LabelsChanged
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
