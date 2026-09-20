<?php

namespace Cooolinho\FilamentMailbox\OAuth;

use Cooolinho\FilamentMailbox\Data\OAuthToken;
use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Events\OAuthConnectionRevoked;
use Cooolinho\FilamentMailbox\Exceptions\OAuthReconnectRequired;
use Cooolinho\FilamentMailbox\Exceptions\OAuthRequestFailed;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Hands out valid access tokens and renews them shortly before they expire.
 */
class OAuthTokenManager
{
    public function __construct(
        protected OAuthProviders $providers,
    ) {}

    /**
     * @throws OAuthReconnectRequired when the refresh token is no longer valid
     * @throws OAuthRequestFailed on other errors of the authorization server
     */
    public function accessToken(OAuthConnection $connection): string
    {
        $this->ensureActive($connection);

        if ($this->isFresh($connection)) {
            return $connection->access_token;
        }

        // The lock prevents parallel jobs from refreshing (and rotating) the same token twice.
        return Cache::lock('filament-mailbox:oauth-refresh:'.$connection->getKey(), 30)
            ->block(30, function () use ($connection): string {
                $connection->refresh();
                $this->ensureActive($connection);

                if ($this->isFresh($connection)) {
                    return $connection->access_token;
                }

                return $this->renew($connection);
            });
    }

    /**
     * @throws OAuthReconnectRequired
     */
    public function renew(OAuthConnection $connection): string
    {
        $provider = $this->providers->for($connection->application->provider);

        try {
            $token = match ($connection->grant_type) {
                OAuthGrantType::ClientCredentials, OAuthGrantType::ServiceAccount => $provider->clientCredentials($connection->application, $connection->scopes, $connection->account_email),
                OAuthGrantType::AuthorizationCode => filled($connection->refresh_token)
                    ? $provider->refresh($connection->application, $connection->refresh_token, $connection->scopes)
                    : throw new OAuthRequestFailed('No refresh token stored.', 'invalid_grant'),
            };
        } catch (OAuthRequestFailed $exception) {
            $connection->forceFill(['last_error' => $exception->getMessage()])->save();

            if ($exception->isInvalidGrant()) {
                $this->markNeedsReconnect($connection, $exception->getMessage());
            }

            throw $exception->isInvalidGrant() ? OAuthReconnectRequired::forConnection($connection) : $exception;
        }

        $this->store($connection, $token);

        return $token->accessToken;
    }

    public function store(OAuthConnection $connection, OAuthToken $token): void
    {
        $connection->forceFill([
            'access_token' => $token->accessToken,
            'access_token_expires_at' => $token->expiresAt,
            // Microsoft rotates refresh tokens; keep the old one when none is returned.
            'refresh_token' => $token->refreshToken ?? $connection->refresh_token,
            'status' => OAuthConnectionStatus::Active,
            'last_error' => null,
        ])->save();
    }

    public function markNeedsReconnect(OAuthConnection $connection, string $reason): void
    {
        $wasActive = $connection->isActive();

        $connection->forceFill([
            'status' => OAuthConnectionStatus::NeedsReconnect,
            'access_token' => null,
            'access_token_expires_at' => null,
            'last_error' => $reason,
        ])->save();

        if ($wasActive) {
            OAuthConnectionRevoked::dispatch($connection);
        }
    }

    protected function isFresh(OAuthConnection $connection): bool
    {
        $margin = (int) config('filament-mailbox.oauth.refresh_margin_seconds', 300);

        return filled($connection->access_token)
            && $connection->access_token_expires_at?->isAfter(now()->addSeconds($margin));
    }

    protected function ensureActive(OAuthConnection $connection): void
    {
        if (! $connection->isActive()) {
            throw OAuthReconnectRequired::forConnection($connection);
        }
    }
}
