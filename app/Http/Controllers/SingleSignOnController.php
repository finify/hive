<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Identity\SingleSignOn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff sign-in with Microsoft. Only people who already have an active Hive
 * account can get in (invitations still decide who has one); the account
 * is matched on the Entra object id, or on email the first time. Multi-factor
 * authentication is Entra's job for these sign-ins.
 */
class SingleSignOnController extends Controller
{
    public function redirect(Request $request, SingleSignOn $sso): RedirectResponse
    {
        abort_unless($sso->isConfigured(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('sso', compact('state', 'nonce', 'verifier'));

        return redirect()->away($sso->authorizationUrl($state, $nonce, $verifier, route('sso.callback')));
    }

    public function callback(Request $request, SingleSignOn $sso): RedirectResponse
    {
        abort_unless($sso->isConfigured(), 404);
        $expected = $request->session()->pull('sso');

        if (! is_array($expected) || ! hash_equals((string) $expected['state'], (string) $request->query('state')) || ! $request->filled('code')) {
            return $this->fail('That sign-in link has expired. Try again.');
        }

        try {
            $identity = $sso->identify((string) $request->query('code'), (string) $expected['verifier'], (string) $expected['nonce'], route('sso.callback'));
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Microsoft sign-in didn’t work. Try again, or sign in with your password.');
        }

        $user = User::query()->where('entra_object_id', $identity['id'])->first()
            ?? User::query()->whereNull('entra_object_id')->where('email', $identity['email'])->first();

        if ($user === null || ! $user->is_active) {
            activity('identity')->withProperties(['email' => $identity['email'], 'ip' => $request->ip(), 'via' => $sso->name()])
                ->event('sign-in-failed')->log('Failed sign-in: no active Hive account');

            return $this->fail('There’s no active Hive account for '.$identity['email'].'. Ask HR for an invitation.');
        }

        if ($user->entra_object_id === null) {
            $user->forceFill(['entra_object_id' => $identity['id']])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_via', 'entra');

        return redirect()->intended(route('dashboard'));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
