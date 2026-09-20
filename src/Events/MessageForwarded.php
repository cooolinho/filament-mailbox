<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Foundation\Events\Dispatchable;

class MessageForwarded
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $recipients
     */
    public function __construct(
        public MailboxMessage $message,
        public array $recipients,
        public bool $asAttachment,
    ) {}
}
