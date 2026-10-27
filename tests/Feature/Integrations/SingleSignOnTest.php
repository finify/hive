<?php

use App\Services\Identity\Jwt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const TENANT = '11111111-2222-3333-4444-555555555555';
const CLIENT = 'hive-client-id';

/**
 * A key pair standing in for Microsoft's, and an ID token signed with it.
 *
 * @return array{jwks: array<string, mixed>, sign: Closure}
 */
function entraKeys(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);
    $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => Jwt::encode($details['rsa']['n']), 'e' => Jwt::encode($details['rsa']['e'])]]];

    $sign = function (array $claims) use ($key): string {
        $head = Jwt::encode(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
        $body = Jwt::encode(json_encode($claims));
        openssl_sign("{$head}.{$body}", $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$head}.{$body}.".Jwt::encode($signature);
    };

    return ['jwks' => $jwks, 'sign' => $sign];
}

function signInWithMicrosoft(array $claimOverrides = []): TestResponse
{
    config(['hive.integrations.entra' => ['tenant_id' => TENANT, 'client_id' => CLIENT, 'client_secret' => 'secret']]);
    $keys = entraKeys();

    $redirect = test()->get(route('sso.redirect'));
    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    $nonce = session('sso.nonce');

    $token = ($keys['sign'])($claimOverrides + [
        'iss' => 'https://login.microsoftonline.com/'.TENANT.'/v2.0', 'aud' => CLIENT, 'tid' => TENANT,
        'exp' => time() + 600, 'nbf' => time() - 10, 'nonce' => $nonce,
        'oid' => 'oid-hannah', 'email' => 'Hannah@lumenco.test', 'name' => 'Hannah Clarke',
    ]);

    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['id_token' => $token]),
        'login.microsoftonline.com/*/discovery/v2.0/keys' => Http::response($keys['jwks']),
    ]);

    return test()->get(route('sso.callback', ['state' => $query['state'], 'code' => 'auth-code']));
}

it('hides Microsoft sign-in until it is configured', function () {
    $this->get(route('login'))->assertDontSee('Sign in with Microsoft');
    $this->get(route('sso.redirect'))->assertNotFound();
});

it('sends people to Microsoft with PKCE and a nonce', function () {
    config(['hive.integrations.entra' => ['tenant_id' => TENANT, 'client_id' => CLIENT, 'client_secret' => 'secret']]);
    $this->get(route('login'))->assertSee('Sign in with Microsoft');

    $location = (string) $this->get(route('sso.redirect'))->headers->get('Location');

    expect($location)->toStartWith('https://login.microsoftonline.com/'.TENANT.'/oauth2/v2.0/authorize?')
        ->toContain('code_challenge_method=S256')
        ->toContain('nonce=');
});

it('signs in an existing user with a valid ID token, and links their Microsoft id', function () {
    signInWithMicrosoft()->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs(persona('hannah'));
    expect(persona('hannah')->refresh()->entra_object_id)->toBe('oid-hannah');
});

it('lets a Microsoft sign-in past Hive’s own two-factor step for roles that need it', function () {
    persona('priya')->forceFill(['two_factor_confirmed_at' => null])->save();
    config(['hive.enforce_two_factor' => true]);

    signInWithMicrosoft(['oid' => 'oid-priya', 'email' => 'priya@lumenco.test']);
    $this->get(route('dashboard'))->assertOk();
});

it('refuses tokens that fail a check, and people without an account', function (array $claims, string $message) {
    signInWithMicrosoft($claims)->assertRedirect(route('login'))->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain($message);
    $this->assertGuest();
})->with([
    'wrong audience' => [['aud' => 'someone-else'], 'Microsoft sign-in didn’t work'],
    'wrong tenant' => [['tid' => 'other-tenant'], 'Microsoft sign-in didn’t work'],
    'expired' => [['exp' => time() - 3600], 'Microsoft sign-in didn’t work'],
    'replayed nonce' => [['nonce' => 'not-the-one'], 'Microsoft sign-in didn’t work'],
    'no account' => [['oid' => 'oid-x', 'email' => 'stranger@example.test'], 'no active Hive account'],
]);

it('rejects a token signed by another key', function () {
    $other = entraKeys();
    $mine = entraKeys();
    $token = ($other['sign'])(['sub' => 'x']);

    expect(fn () => Jwt::verify($token, $mine['jwks']['keys']))->toThrow(RuntimeException::class, 'signature is not valid');
});

it('rejects a callback with the wrong state', function () {
    config(['hive.integrations.entra' => ['tenant_id' => TENANT, 'client_id' => CLIENT, 'client_secret' => 'secret']]);
    $this->get(route('sso.redirect'));

    $this->get(route('sso.callback', ['state' => 'forged', 'code' => 'x']))->assertSessionHasErrors(['email' => 'That sign-in link has expired. Try again.']);
});
