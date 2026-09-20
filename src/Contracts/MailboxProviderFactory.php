<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Models\Mailbox;

interface MailboxProviderFactory
{
    public function make(Mailbox $mailbox): MailboxProvider;
}
