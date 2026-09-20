<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message composed with a template was sent.
 */
class TemplateUsed
{
    use Dispatchable;

    public function __construct(
        public MailboxTemplate $template,
        public Mailbox $mailbox,
        public ComposeContext $context,
        public int|string|null $userId,
    ) {}
}
