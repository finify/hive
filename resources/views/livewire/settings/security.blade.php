<div>
    <x-page-head title="Security and two-factor" :answer="$enabled ? 'Two-factor authentication is on. You’ll be asked for a code each time you sign in.' : ($required ? 'Your role needs two-factor authentication before you can use Hive.' : 'Add a second step to signing in with an authenticator app.')" />

    <section class="card mw-form">
        <div class="card-body">
            @if ($enabled)
                <div class="d-flex align-items-center gap-2 mb-3"><x-pill tone="positive" icon="shield-check">On</x-pill><span class="text-muted small">Since {{ \App\Support\Display::dateTime($user->two_factor_confirmed_at) }}</span></div>
                @if ($showRecoveryCodes)
                    <p>Store these recovery codes somewhere safe. Each one signs you in once if you lose your phone.</p>
                    <pre class="code bg-surface border rounded-3 p-3">{{ implode("\n", $user->recoveryCodes()) }}</pre>
                @endif
                <label for="sec-pw" class="form-label">Confirm your password to manage two-factor</label>
                <input id="sec-pw" type="password" @class(['form-control mb-2', 'is-invalid' => $errors->has('password')]) wire:model="password" autocomplete="current-password">
                @error('password')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" wire:click="regenerate" wire:loading.attr="disabled">New recovery codes</button>
                    @unless ($required)
                        <button type="button" class="btn btn-quiet text-negative" wire:click="disable" wire:loading.attr="disabled">Turn off two-factor</button>
                    @endunless
                </div>
            @elseif ($pending)
                <p>Scan this with an authenticator app such as Microsoft Authenticator or 1Password, then enter the 6-digit code it shows.</p>
                <div class="bg-white d-inline-block p-2 rounded-3 border mb-3">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <p class="small text-muted">Can’t scan it? Enter this key instead: <span class="code">{{ decrypt($user->two_factor_secret) }}</span></p>
                <form wire:submit="confirm" class="d-flex gap-2 align-items-start">
                    <div>
                        <label for="sec-code" class="visually-hidden">Authentication code</label>
                        <input id="sec-code" inputmode="numeric" autocomplete="one-time-code" @class(['form-control tnum', 'is-invalid' => $errors->has('code')]) wire:model="code" placeholder="123456">
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Confirm and turn on</button>
                </form>
            @else
                <label for="sec-pw2" class="form-label">Confirm your password to start</label>
                <input id="sec-pw2" type="password" @class(['form-control mb-3', 'is-invalid' => $errors->has('password')]) wire:model="password" autocomplete="current-password">
                @error('password')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                <button type="button" class="btn btn-primary" wire:click="enable" wire:loading.attr="disabled">Set up two-factor</button>
            @endif
        </div>
    </section>
</div>
