<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admin, operations director, finance and HR must confirm two-factor
 * authentication before using Hive. Until then they can only reach the
 * security page to set it up.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && config('hive.enforce_two_factor') && $request->session()->get('auth_via') !== 'entra' && $user->requiresTwoFactor() && ! $user->hasConfirmedTwoFactor()) {
            if ($request->expectsJson()) {
                abort(403, 'Set up two-factor authentication to continue.');
            }

            return redirect()->route('security')->with('toast', ['message' => 'Your role needs two-factor authentication. Set it up to continue.', 'type' => 'error']);
        }

        return $next($request);
    }
}
