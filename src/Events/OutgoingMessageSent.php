<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Illuminate\Foundation\Events\Dispatchable;

class OutgoingMessageSent
{
    use Dispatchable;

    public function __construct(
        public MailboxOutgoingMessage $message,
    ) {}
}
