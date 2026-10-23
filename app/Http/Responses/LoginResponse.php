<?php

namespace App\Http\Responses;

use App\Demo\DemoData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After sign-in, demo personas land on the screen their walkthrough starts
 * from; everyone else goes where they were heading, or the dashboard.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function __construct(private readonly DemoData $demo) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        return new RedirectResponse(redirect()->intended($this->home($request))->getTargetUrl());
    }

    private function home(Request $request): string
    {
        if (config('hive.demo.enabled')) {
            $persona = collect($this->demo->raw('personas'))->firstWhere('email', $request->user()?->email);

            if ($persona && Route::has($persona['start'])) {
                return route($persona['start']);
            }
        }

        return route('dashboard');
    }
}
