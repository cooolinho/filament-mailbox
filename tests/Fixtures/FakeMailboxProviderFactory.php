<?php

namespace Cooolinho\FilamentMailbox\Tests\Fixtures;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Models\Mailbox;

class FakeMailboxProviderFactory implements MailboxProviderFactory
{
    /** @var array<int, Mailbox> */
    public array $made = [];

    public function __construct(
        public FakeMailboxProvider $provider = new FakeMailboxProvider,
    ) {}

    public function make(Mailbox $mailbox): MailboxProvider
    {
        $this->made[] = $mailbox;

        return $this->provider;
    }
}
