<?php

namespace App\Http\Controllers;

use App\Actions\Identity\AcceptInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = AcceptInvitation::find($token);

        return view('auth.accept-invitation', [
            'invitation' => $invitation && $invitation->isPending() ? $invitation : null,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): RedirectResponse
    {
        $user = $accept->handle($token, $request->only('name', 'password', 'password_confirmation'));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('toast', ['message' => 'Welcome to Hive, '.$user->name.'.']);
    }
}
