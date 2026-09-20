<?php

namespace Cooolinho\FilamentMailbox\OAuth;

use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Filament\Facades\Filament;
use Throwable;

class OAuthConnectionManager
{
    public function __construct(
        protected OAuthProviders $providers,
        protected OAuthTokenManager $tokens,
    ) {}

    /**
     * App-only connection (Microsoft client credentials, Google service account)
     * for the given mailbox address. A token is requested right away to
     * validate the registration.
     */
    public function connectApplication(OAuthApplication $application, string $email, ProviderType $mailboxProvider = ProviderType::Imap): OAuthConnection
    {
        $provider = $this->providers->for($application->provider);
        $grant = $provider->applicationGrant();
        $scopes = $provider->scopes($grant, $mailboxProvider);

        // App-only tokens differ per resource (Exchange Online vs. Graph), so connections are per scope set.
        $connection = OAuthConnection::query()
            ->where('application_id', $application->getKey())
            ->where('grant_type', $grant)
            ->where('account_email', $email)
            ->get()
            ->first(fn (OAuthConnection $connection): bool => $connection->scopes === $scopes)
            ?? new OAuthConnection([
                'application_id' => $application->getKey(),
                'grant_type' => $grant,
                'account_email' => $email,
            ]);

        if (! $connection->exists) {
            $connection->created_by = Filament::auth()->id();
        }

        $connection->scopes = $scopes;
        $connection->setRelation('application', $application);

        $this->tokens->store($connection, $provider->clientCredentials($application, $connection->scopes, $email));

        return $connection;
    }

    /**
     * Detach the connection from the mailbox. Tokens are revoked and deleted
     * when no other mailbox uses the connection anymore.
     */
    public function disconnect(Mailbox $mailbox): void
    {
        $connection = $mailbox->oauthConnection;

        $mailbox->forceFill(['auth_mode' => AuthMode::Password, 'oauth_connection_id' => null])->save();

        if ($connection) {
            $this->forget($connection);
        }
    }

    public function forget(OAuthConnection $connection): void
    {
        if ($connection->mailboxes()->exists()) {
            return;
        }

        $token = $connection->refresh_token ?? $connection->access_token;

        if ($token) {
            try {
                $this->providers->for($connection->application->provider)->revoke($connection->application, $token);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $connection->delete();
    }
}
