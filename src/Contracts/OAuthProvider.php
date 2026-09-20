<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\OAuthToken;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Exceptions\OAuthRequestFailed;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;

/**
 * Talks to the authorization server of an identity provider.
 */
interface OAuthProvider
{
    /**
     * Default scopes for the given grant and mailbox provider (IMAP/SMTP or an API).
     *
     * @return array<int, string>
     */
    public function scopes(OAuthGrantType $grant, ProviderType $provider = ProviderType::Imap): array;

    /**
     * @param  array<int, string>  $scopes
     */
    public function authorizationUrl(OAuthApplication $application, string $redirectUri, string $state, string $codeChallenge, array $scopes): string;

    /**
     * @throws OAuthRequestFailed
     */
    public function exchangeCode(OAuthApplication $application, string $code, string $codeVerifier, string $redirectUri): OAuthToken;

    /**
     * @param  array<int, string>  $scopes
     *
     * @throws OAuthRequestFailed
     */
    public function refresh(OAuthApplication $application, string $refreshToken, array $scopes): OAuthToken;

    /**
     * App-only token without a signed-in user: Microsoft client credentials,
     * or a Google service account acting for $subject (domain-wide delegation).
     *
     * @param  array<int, string>  $scopes
     *
     * @throws OAuthRequestFailed
     */
    public function clientCredentials(OAuthApplication $application, array $scopes, ?string $subject = null): OAuthToken;

    /**
     * The grant used for app-only connections of this identity provider.
     */
    public function applicationGrant(): OAuthGrantType;

    /**
     * Revoke a token at the provider. Returns false when the provider has no
     * revocation endpoint.
     */
    public function revoke(OAuthApplication $application, string $token): bool;
}
