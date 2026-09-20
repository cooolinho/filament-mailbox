<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Illuminate\Foundation\Events\Dispatchable;

class DeliveryReportReceived
{
    use Dispatchable;

    public function __construct(
        public MailboxReceipt $receipt,
    ) {}
}
