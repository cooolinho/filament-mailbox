<?php

namespace Cooolinho\FilamentMailbox\OAuth\Providers;

use Cooolinho\FilamentMailbox\Data\OAuthToken;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Exceptions\OAuthRequestFailed;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Illuminate\Support\Facades\Http;

/**
 * Google OAuth 2.0. IMAP/SMTP with XOAUTH2 require the restricted scope
 * https://mail.google.com/.
 */
class GoogleOAuthProvider extends AbstractOAuthProvider
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function scopes(OAuthGrantType $grant, ProviderType $provider = ProviderType::Imap): array
    {
        // The Gmail API works with gmail.modify + gmail.send instead of the full mail.google.com scope.
        $scopes = $provider === ProviderType::Gmail
            ? ['https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/gmail.send']
            : ['https://mail.google.com/'];

        return $grant === OAuthGrantType::ServiceAccount ? $scopes : [...$scopes, 'openid', 'email'];
    }

    public function authorizationUrl(OAuthApplication $application, string $redirectUri, string $state, string $codeChallenge, array $scopes): string
    {
        return static::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $application->client_id,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            // Always return a refresh token.
            'access_type' => 'offline',
            'prompt' => 'consent',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function applicationGrant(): OAuthGrantType
    {
        return OAuthGrantType::ServiceAccount;
    }

    /**
     * Service account with domain-wide delegation (JWT bearer grant, RFC 7523).
     * The JSON key is stored in the application's "certificate" field.
     */
    public function clientCredentials(OAuthApplication $application, array $scopes, ?string $subject = null): OAuthToken
    {
        $key = json_decode((string) $application->certificate, true);

        if (! is_array($key) || ! is_string($key['private_key'] ?? null) || ! is_string($key['client_email'] ?? null)) {
            throw new OAuthRequestFailed('The Google application needs a service account JSON key.');
        }

        if (blank($subject)) {
            throw new OAuthRequestFailed('A service account needs the mailbox address to act for.');
        }

        $privateKey = openssl_pkey_get_private($key['private_key']);

        if (! $privateKey) {
            throw new OAuthRequestFailed('The service account private key is invalid.');
        }

        $input = static::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key['private_key_id'] ?? null]))
            .'.'.static::base64UrlEncode((string) json_encode([
                'iss' => $key['client_email'],
                'sub' => $subject,
                'scope' => implode(' ', $scopes),
                'aud' => static::TOKEN_URL,
                'iat' => now()->getTimestamp(),
                'exp' => now()->addHour()->getTimestamp(),
            ]));

        openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $response = Http::asForm()->acceptJson()->timeout(30)->post(static::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $input.'.'.static::base64UrlEncode($signature),
        ]);

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw OAuthRequestFailed::fromResponse('service account token', $response);
        }

        return new OAuthToken(
            accessToken: $response->json('access_token'),
            expiresAt: now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
            scopes: $scopes,
            email: $subject,
        );
    }

    public function revoke(OAuthApplication $application, string $token): bool
    {
        return Http::asForm()->timeout(15)->post(static::REVOKE_URL, ['token' => $token])->successful();
    }

    protected function tokenUrl(OAuthApplication $application): string
    {
        return static::TOKEN_URL;
    }
}
