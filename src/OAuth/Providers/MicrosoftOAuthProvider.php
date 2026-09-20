<?php

namespace Cooolinho\FilamentMailbox\OAuth\Providers;

use Cooolinho\FilamentMailbox\Data\OAuthToken;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Exceptions\OAuthRequestFailed;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Illuminate\Support\Str;

/**
 * Microsoft identity platform (Entra ID) v2.0 endpoints.
 */
class MicrosoftOAuthProvider extends AbstractOAuthProvider
{
    public function scopes(OAuthGrantType $grant, ProviderType $provider = ProviderType::Imap): array
    {
        if ($provider === ProviderType::Graph) {
            return match ($grant) {
                OAuthGrantType::ClientCredentials => [rtrim(static::graphResource(), '/').'/.default'],
                default => ['https://graph.microsoft.com/Mail.ReadWrite', 'https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/User.Read', 'offline_access', 'openid', 'email'],
            };
        }

        return match ($grant) {
            OAuthGrantType::ClientCredentials => ['https://outlook.office365.com/.default'],
            default => [
                'https://outlook.office.com/IMAP.AccessAsUser.All',
                'https://outlook.office.com/SMTP.Send',
                'offline_access',
                'openid',
                'email',
            ],
        };
    }

    /**
     * Graph resource for app-only tokens, derived from the configured base URL (national clouds).
     */
    public static function graphResource(): string
    {
        $url = parse_url((string) config('filament-mailbox.graph.base_url', 'https://graph.microsoft.com/v1.0'));

        return ($url['scheme'] ?? 'https').'://'.($url['host'] ?? 'graph.microsoft.com');
    }

    public function authorizationUrl(OAuthApplication $application, string $redirectUri, string $state, string $codeChallenge, array $scopes): string
    {
        return $this->endpoint($application, 'authorize').'?'.http_build_query([
            'client_id' => $application->client_id,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function applicationGrant(): OAuthGrantType
    {
        return OAuthGrantType::ClientCredentials;
    }

    public function clientCredentials(OAuthApplication $application, array $scopes, ?string $subject = null): OAuthToken
    {
        return $this->requestToken($application, 'client credentials', [
            'grant_type' => 'client_credentials',
            'scope' => implode(' ', $scopes),
        ]);
    }

    public function revoke(OAuthApplication $application, string $token): bool
    {
        // Microsoft has no endpoint to revoke a single refresh token; sessions
        // are revoked in the Entra admin center.
        return false;
    }

    protected function tokenUrl(OAuthApplication $application): string
    {
        return $this->endpoint($application, 'token');
    }

    protected function endpoint(OAuthApplication $application, string $name): string
    {
        $authority = rtrim((string) ($application->extra['authority'] ?? config('filament-mailbox.oauth.providers.microsoft.authority')), '/');

        return sprintf('%s/%s/oauth2/v2.0/%s', $authority, rawurlencode($application->tenant ?: 'common'), $name);
    }

    /**
     * A client secret, or a signed client assertion when a certificate is configured.
     */
    protected function clientAuthentication(OAuthApplication $application, string $tokenUrl): array
    {
        if (blank($application->certificate)) {
            return parent::clientAuthentication($application, $tokenUrl);
        }

        return [
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $this->clientAssertion($application, $tokenUrl),
        ];
    }

    /**
     * @throws OAuthRequestFailed
     */
    public function clientAssertion(OAuthApplication $application, string $audience): string
    {
        $pem = (string) $application->certificate;
        $key = openssl_pkey_get_private($pem);
        $certificate = openssl_x509_read($pem);

        if (! $key || ! $certificate || ! openssl_x509_export($certificate, $exported)) {
            throw new OAuthRequestFailed('The configured certificate must contain a PEM private key and certificate.');
        }

        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $exported) ?? '');

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'x5t#S256' => static::base64UrlEncode(hash('sha256', $der, true))];
        $payload = [
            'aud' => $audience,
            'iss' => $application->client_id,
            'sub' => $application->client_id,
            'jti' => (string) Str::uuid(),
            'nbf' => now()->getTimestamp(),
            'exp' => now()->addMinutes(10)->getTimestamp(),
        ];

        $input = static::base64UrlEncode((string) json_encode($header)).'.'.static::base64UrlEncode((string) json_encode($payload));

        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

        return $input.'.'.static::base64UrlEncode($signature);
    }
}
