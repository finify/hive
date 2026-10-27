<?php

namespace App\Services\Identity;

use RuntimeException;

/**
 * Just enough JSON Web Token handling to check an OpenID Connect ID token
 * signed with RS256 against a provider's published RSA keys (JWKS).
 */
final class Jwt
{
    /**
     * @param  list<array<string, mixed>>  $keys  the provider's JWKS "keys"
     * @return array<string, mixed> the verified claims
     */
    public static function verify(string $token, array $keys): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('The ID token is not a JWT.');
        }

        [$head, $body, $signature] = $parts;
        $header = json_decode(self::decode($head), true);
        $claims = json_decode(self::decode($body), true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('The ID token must be signed with RS256.');
        }

        $key = collect($keys)->first(fn ($k) => ($k['kid'] ?? null) === ($header['kid'] ?? null) && ($k['kty'] ?? null) === 'RSA');

        if (! is_array($key) || ! isset($key['n'], $key['e'])) {
            throw new RuntimeException('No published key matches the ID token.');
        }

        $pem = self::rsaPublicKeyPem((string) $key['n'], (string) $key['e']);

        if (openssl_verify("{$head}.{$body}", self::decode($signature), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The ID token signature is not valid.');
        }

        return $claims;
    }

    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            throw new RuntimeException('Bad base64url in the token.');
        }

        return $decoded;
    }

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * A PEM SubjectPublicKeyInfo built from a JWK's modulus and exponent.
     */
    public static function rsaPublicKeyPem(string $n, string $e): string
    {
        $modulus = self::decode($n);
        $exponent = self::decode($e);

        $int = fn (string $bytes) => "\x02".self::length(strlen(ltrim($bytes, "\x00")) + (ord(ltrim($bytes, "\x00")[0]) > 0x7F ? 1 : 0))
            .(ord(ltrim($bytes, "\x00")[0]) > 0x7F ? "\x00" : '').ltrim($bytes, "\x00");
        $rsaKey = $int($modulus).$int($exponent);
        $rsaKey = "\x30".self::length(strlen($rsaKey)).$rsaKey;
        $bitString = "\x03".self::length(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $algorithm = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $spki = "\x30".self::length(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
