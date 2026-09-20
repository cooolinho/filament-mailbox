<?php

namespace Cooolinho\FilamentMailbox\Providers;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves providers from the "filament-mailbox.providers" registry.
 */
class DefaultMailboxProviderFactory implements MailboxProviderFactory
{
    public function __construct(
        protected Container $container,
    ) {}

    public function make(Mailbox $mailbox): MailboxProvider
    {
        $type = $mailbox->provider->value;
        $class = config("filament-mailbox.providers.{$type}");

        if (! is_string($class) || ! is_subclass_of($class, MailboxProvider::class)) {
            throw new InvalidArgumentException("No mailbox provider registered for [{$type}].");
        }

        return $this->container->make($class, ['mailbox' => $mailbox]);
    }
}
