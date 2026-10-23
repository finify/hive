<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like `can:`, but passes when the user holds any one of the permissions:
 * `can_any:timesheets.approve,leave.approve`.
 */
class AuthorizeAny
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless($request->user()?->canAny($permissions), 403);

        return $next($request);
    }
}
