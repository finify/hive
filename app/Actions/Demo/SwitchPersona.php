<?php

namespace App\Actions\Demo;

use App\Demo\DemoData;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Signs in as another demo persona without signing out, so the demo changes
 * held in the session carry across personas. Only in demo mode, and only to
 * users listed in database/demo/personas.json.
 */
class SwitchPersona
{
    public function handle(string $email): User
    {
        abort_unless(config('hive.demo.enabled'), 404);

        $personas = collect(app(DemoData::class)->raw('personas'))->pluck('email');
        abort_unless($personas->contains($email), 404);

        $user = User::query()->active()->where('email', $email)->firstOrFail();

        $from = Auth::user();
        Auth::guard('web')->login($user);
        session()->regenerate();

        activity('identity')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['from' => $from?->email])
            ->event('persona-switched')
            ->log('Switched demo persona to '.$user->name);

        return $user;
    }
}
