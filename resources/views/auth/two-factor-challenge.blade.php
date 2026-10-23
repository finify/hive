@php
    $demoCode = null;
    if (config('hive.demo.enabled') && session('login.id')) {
        $pending = \App\Models\User::find(session('login.id'));
        $isPersona = $pending && collect(app(\App\Demo\DemoData::class)->raw('personas'))->pluck('email')->contains($pending->email);
        if ($isPersona && $pending->two_factor_secret) {
            $demoCode = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp(decrypt($pending->two_factor_secret));
        }
    }
@endphp
<x-auth-card title="Two-factor check" intro="Enter the 6-digit code from your authenticator app.">
    <div x-data="{ recovery: false, code: '' }">
        @if ($demoCode)
            <div class="callout mb-3">
                <i class="bi bi-phone" aria-hidden="true"></i>
                <div>
                    <div class="fw-medium">Demo: the authenticator shows <span class="code fs-6">{{ $demoCode }}</span></div>
                    <button type="button" class="btn btn-link link p-0 small" @click="code = @js($demoCode); $nextTick(() => $refs.form.requestSubmit())">Use this code</button>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.login') }}" x-ref="form">
            @csrf
            <div class="mb-3" x-show="! recovery">
                <label for="code" class="form-label">Authentication code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" x-model="code" @class(['form-control tnum', 'is-invalid' => $errors->has('code')]) autofocus>
                @error('code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3" x-show="recovery" x-cloak>
                <label for="recovery_code" class="form-label">Recovery code</label>
                <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary w-100">Continue</button>
        </form>

        <button type="button" class="btn btn-link link small p-0 mt-3" @click="recovery = ! recovery" x-text="recovery ? 'Use an authentication code' : 'Use a recovery code instead'"></button>
    </div>
</x-auth-card>
