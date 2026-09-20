<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Illuminate\Foundation\Events\Dispatchable;

class ReadReceiptReceived
{
    use Dispatchable;

    public function __construct(
        public MailboxReceipt $receipt,
    ) {}
}
