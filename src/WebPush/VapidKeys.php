<?php

namespace Cooolinho\FilamentMailbox\WebPush;

use RuntimeException;

/**
 * VAPID (RFC 8292) keys and JWT signing with ext-openssl, no extra package.
 * Keys are base64url strings: public = uncompressed P-256 point (65 bytes), private = d (32 bytes).
 */
class VapidKeys
{
    /**
     * @return array{public: string, private: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a P-256 key: '.openssl_error_string());
        }

        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'public' => static::base64UrlEncode("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            'private' => static::base64UrlEncode(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    /**
     * JWT for the "Authorization: vapid t=..., k=..." header of a push request.
     */
    public static function jwt(string $audience, string $subject, string $publicKey, string $privateKey, int $ttl = 43200): string
    {
        $header = static::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = static::base64UrlEncode(json_encode(['aud' => $audience, 'exp' => time() + $ttl, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $data = "{$header}.{$claims}";

        if (! openssl_sign($data, $signature, static::pem($publicKey, $privateKey), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }

        return $data.'.'.static::base64UrlEncode(static::derToRaw($signature));
    }

    /**
     * SEC1 ECPrivateKey (RFC 5915) for the prime256v1 curve.
     */
    public static function pem(string $publicKey, string $privateKey): string
    {
        $public = static::base64UrlDecode($publicKey);
        $private = static::base64UrlDecode($privateKey);

        if (strlen($public) !== 65 || strlen($private) !== 32) {
            throw new RuntimeException('Invalid VAPID keys.');
        }

        $der = "\x30\x77\x02\x01\x01\x04\x20".$private
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$public;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * ECDSA signature: DER SEQUENCE(r, s) → r||s (64 bytes) as required by JWS.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
