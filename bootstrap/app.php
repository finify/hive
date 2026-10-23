<?php

use App\Http\Middleware\AuthorizeAny;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnsureUserIsActive::class]);
        $middleware->alias(['two-factor.required' => RequireTwoFactor::class, 'can_any' => AuthorizeAny::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
