<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;

class ConnectionTester
{
    public function __construct(
        protected MailboxProviderFactory $providers,
    ) {}

    /**
     * @throws ConnectionFailed
     */
    public function test(Mailbox $mailbox): void
    {
        $provider = $this->providers->make($mailbox);

        try {
            $provider->testConnection();
        } finally {
            $provider->disconnect();
        }
    }
}
