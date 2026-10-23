<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Two-factor authentication: set up with an authenticator app, confirm with
 * a code, keep recovery codes. Mandatory for super admin, operations
 * director, finance and HR.
 */
#[Layout('layouts.app')]
#[Title('Security')]
class Security extends Component
{
    public string $password = '';

    public string $code = '';

    public bool $showRecoveryCodes = false;

    private function confirmPassword(): bool
    {
        if (! Hash::check($this->password, auth()->user()->password)) {
            $this->addError('password', 'That password isn’t right.');

            return false;
        }

        $this->reset('password');

        return true;
    }

    public function enable(EnableTwoFactorAuthentication $enable): void
    {
        if (! $this->confirmPassword()) {
            return;
        }

        $enable(auth()->user());
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->validate(['code' => ['required', 'digits:6']], ['code.digits' => 'Enter the 6-digit code from your app.']);
        $confirm(auth()->user(), $this->code);
        $this->reset('code');
        $this->showRecoveryCodes = true;

        activity('identity')->causedBy(auth()->user())->performedOn(auth()->user())->event('two-factor-enabled')->log('Turned on two-factor authentication');
        $this->dispatch('toast', message: 'Two-factor authentication is on.');
    }

    public function regenerate(GenerateNewRecoveryCodes $generate): void
    {
        if (! $this->confirmPassword()) {
            return;
        }

        $generate(auth()->user());
        $this->showRecoveryCodes = true;
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        if (auth()->user()->requiresTwoFactor() && config('hive.enforce_two_factor')) {
            $this->addError('password', 'Your role needs two-factor authentication, so it can’t be turned off.');

            return;
        }

        if (! $this->confirmPassword()) {
            return;
        }

        $disable(auth()->user());
        activity('identity')->causedBy(auth()->user())->performedOn(auth()->user())->event('two-factor-disabled')->log('Turned off two-factor authentication');
        $this->dispatch('toast', message: 'Two-factor authentication is off.');
    }

    public function render(): View
    {
        $user = auth()->user()->fresh();

        return view('livewire.settings.security', [
            'user' => $user,
            'pending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'enabled' => $user->hasConfirmedTwoFactor(),
            'required' => $user->requiresTwoFactor(),
        ]);
    }
}
