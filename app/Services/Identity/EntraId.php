<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Entra ID (Azure AD) sign-in with OpenID Connect: authorisation
 * code with PKCE, then the ID token's signature, issuer, audience, expiry
 * and nonce are checked against the tenant's published keys.
 */
class EntraId implements SingleSignOn
{
    public function name(): string
    {
        return 'Microsoft';
    }

    public function isConfigured(): bool
    {
        return filled(config('hive.integrations.entra.tenant_id')) && filled(config('hive.integrations.entra.client_id')) && filled(config('hive.integrations.entra.client_secret'));
    }

    public function authorizationUrl(string $state, string $nonce, string $codeVerifier, string $redirectUri): string
    {
        return $this->base().'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => config('hive.integrations.entra.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Jwt::encode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    public function identify(string $code, string $codeVerifier, string $nonce, string $redirectUri): array
    {
        $token = Http::asForm()->timeout(15)->post($this->base().'/oauth2/v2.0/token', [
            'client_id' => config('hive.integrations.entra.client_id'),
            'client_secret' => config('hive.integrations.entra.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'scope' => 'openid profile email',
        ])->throw()->json('id_token');

        if (! is_string($token)) {
            throw new RuntimeException('Microsoft did not return an ID token.');
        }

        $claims = Jwt::verify($token, $this->keys());
        $tenant = (string) config('hive.integrations.entra.tenant_id');

        $problems = array_filter([
            ($claims['iss'] ?? null) !== "https://login.microsoftonline.com/{$tenant}/v2.0" ? 'issuer' : null,
            ($claims['aud'] ?? null) !== config('hive.integrations.entra.client_id') ? 'audience' : null,
            (int) ($claims['exp'] ?? 0) < time() - 60 ? 'expiry' : null,
            (int) ($claims['nbf'] ?? 0) > time() + 60 ? 'not before' : null,
            ! hash_equals($nonce, (string) ($claims['nonce'] ?? '')) ? 'nonce' : null,
            ($claims['tid'] ?? null) !== $tenant ? 'tenant' : null,
        ]);

        if ($problems !== []) {
            throw new RuntimeException('The ID token failed its checks: '.implode(', ', $problems).'.');
        }

        $email = strtolower((string) ($claims['email'] ?? $claims['preferred_username'] ?? ''));

        if (! isset($claims['oid']) || $email === '') {
            throw new RuntimeException('The ID token has no user id or email.');
        }

        return ['id' => (string) $claims['oid'], 'email' => $email, 'name' => (string) ($claims['name'] ?? $email)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keys(): array
    {
        return Cache::remember('entra.jwks', now()->addHours(6), fn () => array_values((array) Http::timeout(10)
            ->get($this->base().'/discovery/v2.0/keys')->throw()->json('keys', [])));
    }

    private function base(): string
    {
        return 'https://login.microsoftonline.com/'.config('hive.integrations.entra.tenant_id');
    }
}
