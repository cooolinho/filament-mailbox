<?php

namespace Cooolinho\FilamentMailbox\OAuth\Providers;

use Cooolinho\FilamentMailbox\Contracts\OAuthProvider;
use Cooolinho\FilamentMailbox\Data\OAuthToken;
use Cooolinho\FilamentMailbox\Exceptions\OAuthRequestFailed;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Illuminate\Support\Facades\Http;

abstract class AbstractOAuthProvider implements OAuthProvider
{
    abstract protected function tokenUrl(OAuthApplication $application): string;

    public function exchangeCode(OAuthApplication $application, string $code, string $codeVerifier, string $redirectUri): OAuthToken
    {
        return $this->requestToken($application, 'code exchange', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function refresh(OAuthApplication $application, string $refreshToken, array $scopes): OAuthToken
    {
        return $this->requestToken($application, 'token refresh', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', $scopes),
        ]);
    }

    /**
     * @param  array<string, string>  $parameters
     *
     * @throws OAuthRequestFailed
     */
    protected function requestToken(OAuthApplication $application, string $operation, array $parameters): OAuthToken
    {
        $url = $this->tokenUrl($application);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($url, [
                'client_id' => $application->client_id,
                ...$this->clientAuthentication($application, $url),
                ...$parameters,
            ]);

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw OAuthRequestFailed::fromResponse($operation, $response);
        }

        $claims = $this->idTokenClaims($response->json('id_token'));

        return new OAuthToken(
            accessToken: $response->json('access_token'),
            refreshToken: $response->json('refresh_token'),
            expiresAt: now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
            scopes: array_values(array_filter(explode(' ', (string) $response->json('scope')))),
            email: $claims['email'] ?? $claims['preferred_username'] ?? null,
            subject: $claims['sub'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function clientAuthentication(OAuthApplication $application, string $tokenUrl): array
    {
        return array_filter(['client_secret' => $application->client_secret]);
    }

    /**
     * The ID token is received directly from the token endpoint over TLS, so
     * its signature does not need to be validated (OpenID Connect Core 3.1.3.7).
     *
     * @return array<string, string>
     */
    protected function idTokenClaims(mixed $idToken): array
    {
        if (! is_string($idToken) || substr_count($idToken, '.') !== 2) {
            return [];
        }

        $payload = json_decode(static::base64UrlDecode(explode('.', $idToken)[1]), true);

        return is_array($payload)
            ? array_map('strval', array_filter($payload, fn ($value): bool => is_scalar($value)))
            : [];
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
