<?php

namespace Cooolinho\FilamentMailbox\Support;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Models\Mailbox;

/**
 * Request-scoped cache of provider capabilities.
 *
 * Capabilities are static per provider type; creating a provider never
 * connects to the server.
 */
class ProviderCapabilities
{
    /** @var array<string, array<int, ProviderCapability>> */
    protected array $capabilities = [];

    public function __construct(
        protected MailboxProviderFactory $providers,
    ) {}

    /**
     * @return array<int, ProviderCapability>
     */
    public function for(Mailbox $mailbox): array
    {
        return $this->capabilities[$mailbox->provider->value] ??= $this->providers->make($mailbox)->capabilities();
    }

    public function supports(Mailbox $mailbox, ProviderCapability $capability): bool
    {
        return in_array($capability, $this->for($mailbox), true);
    }

    public function flush(): void
    {
        $this->capabilities = [];
    }
}
