<?php

namespace App\Services\Identity;

/**
 * A company identity provider staff can sign in with. Hive still decides
 * who has an account: the provider only proves who someone is.
 */
interface SingleSignOn
{
    public function name(): string;

    public function isConfigured(): bool;

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier, string $redirectUri): string;

    /**
     * Exchanges the code and checks the ID token.
     *
     * @return array{id: string, email: string, name: string}
     */
    public function identify(string $code, string $codeVerifier, string $nonce, string $redirectUri): array;
}
