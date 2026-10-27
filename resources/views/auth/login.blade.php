@php
    $demo = config('hive.demo.enabled');
    $personas = $demo ? app(\App\Demo\DemoData::class)->raw('personas') : [];
    $twoFactorEmails = $demo ? \App\Models\User::query()->whereIn('email', collect($personas)->pluck('email'))->whereNotNull('two_factor_confirmed_at')->pluck('email')->all() : [];
    $first = $personas[0] ?? null;
@endphp
<x-layouts.guest title="Sign in">
    <div class="signin" x-data="{
            email: @js(old('email', $first['email'] ?? '')),
            password: @js($first ? config('hive.demo.password') : ''),
            selected: @js(old('email', $first['email'] ?? '')),
            choose(persona) {
                this.email = persona;
                this.password = @js(config('hive.demo.password'));
                this.selected = persona;
                this.$nextTick(() => this.$refs.form.requestSubmit());
            }
        }">
        <section class="signin-form">
            <div class="brand p-0">
                <x-brand-mark />
                <span>
                    <span class="brand-name d-block">Hive</span>
                    <span class="brand-org d-block">Lumen &amp; Co Live</span>
                </span>
            </div>

            <h1>Sign in to Hive</h1>
            <p class="text-muted mb-4">People, crew, time and money for every event, in one place.</p>

            @if (session('status'))
                <div class="callout mb-3" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i><div>{{ session('status') }}</div></div>
            @endif

            <form method="POST" action="{{ route('login') }}" x-ref="form" novalidate>
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label">Work email</label>
                    <input id="email" name="email" type="email" x-model="email" @class(['form-control', 'is-invalid' => $errors->has('email')]) autocomplete="username" required autofocus>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <label for="password" class="form-label">Password</label>
                        <a href="{{ route('password.request') }}" class="small link">Forgot password?</a>
                    </div>
                    <input id="password" name="password" type="password" x-model="password" @class(['form-control', 'is-invalid' => $errors->has('password')]) autocomplete="current-password" required>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>

            @if (app(\App\Services\Identity\SingleSignOn::class)->isConfigured())
                <a href="{{ route('sso.redirect') }}" class="btn btn-secondary w-100 mt-2 d-inline-flex align-items-center justify-content-center gap-2"><i class="bi bi-microsoft" aria-hidden="true"></i>Sign in with Microsoft</a>
            @endif

            <p class="small text-muted mt-4 mb-0">There is no public sign-up. Ask HR or an admin for an invitation.</p>
            <p class="small text-muted mt-auto pt-5 mb-0">An independent portfolio project. All companies, people and figures are fictional.</p>
        </section>

        @if ($demo)
            <section class="signin-personas" aria-labelledby="personas-title">
                <h2 id="personas-title">Walk through Hive as someone at the agency</h2>
                <p class="text-muted mb-3">It is {{ app(\App\Demo\DemoData::class)->today()->format('l j F Y') }}. The agency’s dates move with the calendar: this week Northwind’s partner summit is on in London and Arclight launches in Manchester, and Kofi is booked on both. Pick a persona to sign in with their details filled in. The password for each is <span class="code">{{ config('hive.demo.password') }}</span>.</p>

                <div class="persona-grid">
                    @foreach ($personas as $persona)
                        @php($role = \App\Enums\Role::from($persona['roles'][0]))
                        <button type="button" class="persona" :class="{ 'is-selected': selected === @js($persona['email']) }" @click="choose(@js($persona['email']))">
                            <span class="persona-top">
                                <x-avatar :name="$persona['name']" :id="$persona['person_id']" />
                                <span class="lh-sm">
                                    <span class="persona-name d-block">{{ $persona['name'] }}</span>
                                    <span class="persona-role d-block">{{ $role->label() }}</span>
                                </span>
                            </span>
                            <span class="persona-line">{{ $persona['headline'] }}</span>
                            <ul class="persona-tour">
                                @foreach ($persona['tour'] as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ul>
                            @if (in_array($persona['email'], $twoFactorEmails, true))
                                <span class="persona-2fa"><i class="bi bi-shield-lock" aria-hidden="true"></i> Two-factor required for this role. The demo shows the current code.</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.guest>
