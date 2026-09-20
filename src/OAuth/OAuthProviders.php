<?php

namespace Cooolinho\FilamentMailbox\OAuth;

use Cooolinho\FilamentMailbox\Contracts\OAuthProvider;
use Cooolinho\FilamentMailbox\Enums\OAuthProviderType;
use Cooolinho\FilamentMailbox\OAuth\Providers\GoogleOAuthProvider;
use Cooolinho\FilamentMailbox\OAuth\Providers\MicrosoftOAuthProvider;
use Illuminate\Contracts\Container\Container;

class OAuthProviders
{
    public function __construct(
        protected Container $container,
    ) {}

    public function for(OAuthProviderType $type): OAuthProvider
    {
        return $this->container->make(match ($type) {
            OAuthProviderType::Microsoft => MicrosoftOAuthProvider::class,
            OAuthProviderType::Google => GoogleOAuthProvider::class,
        });
    }

    /**
     * IMAP and SMTP server settings of a provider.
     *
     * @return array{imap: array{host: string, port: int, encryption: string}, smtp: array{host: string, port: int}}
     */
    public function servers(OAuthProviderType $type): array
    {
        $config = config("filament-mailbox.oauth.providers.{$type->value}");

        return ['imap' => $config['imap'], 'smtp' => $config['smtp']];
    }
}
