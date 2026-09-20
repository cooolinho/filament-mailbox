<?php

namespace Cooolinho\FilamentMailbox\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Google-signed OIDC ID tokens (RS256) as sent by Pub/Sub push
 * subscriptions: signature against Google's JWKs, issuer, audience, expiry
 * and optionally the service account e-mail.
 */
class GoogleIdTokenVerifier
{
    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /**
     * @return array<string, mixed>|null The claims, or null when the token is invalid
     */
    public function verify(string $token, string $audience, ?string $email = null): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $audience === '') {
            return null;
        }

        $header = json_decode(static::decode($parts[0]), true);
        $claims = json_decode(static::decode($parts[1]), true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            return null;
        }

        $key = $this->publicKey($header['kid']);

        if (! $key || openssl_verify($parts[0].'.'.$parts[1], static::decode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $valid = in_array($claims['iss'] ?? null, static::ISSUERS, true)
            && ($claims['aud'] ?? null) === $audience
            && (int) ($claims['exp'] ?? 0) > now()->getTimestamp() - 60
            && (int) ($claims['iat'] ?? PHP_INT_MAX) <= now()->getTimestamp() + 60
            && ($email === null || (($claims['email'] ?? null) === $email && ($claims['email_verified'] ?? false) === true));

        return $valid ? $claims : null;
    }

    protected function publicKey(string $kid): ?string
    {
        $jwk = collect($this->keys())->firstWhere('kid', $kid);

        // Google rotates keys: refresh once when a new kid shows up.
        if (! $jwk) {
            Cache::forget('filament-mailbox:google-jwks');
            $jwk = collect($this->keys())->firstWhere('kid', $kid);
        }

        return is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA' ? static::rsaPem((string) $jwk['n'], (string) $jwk['e']) : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function keys(): array
    {
        return Cache::remember('filament-mailbox:google-jwks', 3600, fn (): array => Http::timeout(10)->get(static::JWKS_URL)->json('keys') ?? []);
    }

    /**
     * PEM encoded SubjectPublicKeyInfo from the RSA modulus and exponent.
     */
    public static function rsaPem(string $n, string $e): string
    {
        $integer = function (string $value): string {
            $value = ltrim($value, "\x00");

            if ($value === '' || ord($value[0]) > 0x7F) {
                $value = "\x00".$value;
            }

            return "\x02".static::length(strlen($value)).$value;
        };

        $rsaKey = $integer(static::decode($n)).$integer(static::decode($e));
        $rsaKey = "\x30".static::length(strlen($rsaKey)).$rsaKey;

        $bitString = "\x03".static::length(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $algorithm = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $info = "\x30".static::length(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($info), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    protected static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    protected static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
