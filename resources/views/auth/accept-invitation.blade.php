<x-auth-card title="Join Hive">
    @if (! $invitation)
        <p class="text-muted">This invitation has expired or has already been used. Invitations last seven days. Ask HR or an admin to send a new one.</p>
        <a href="{{ route('login') }}" class="btn btn-secondary">Go to sign in</a>
    @else
        <p class="text-muted">{{ $invitation->inviter->name }} invited <span class="text-ink">{{ $invitation->email }}</span> to join as {{ $invitation->role->label() }}. Choose your name and a password to finish.</p>
        <form method="POST" action="{{ route('invitations.accept', $token) }}">
            @csrf
            @error('token')
                <div class="callout is-negative mb-3"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div>{{ $message }}</div></div>
            @enderror
            <div class="mb-3">
                <label for="name" class="form-label">Full name</label>
                <input id="name" name="name" value="{{ old('name') }}" @class(['form-control', 'is-invalid' => $errors->has('name')]) autocomplete="name" required autofocus>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input id="password" name="password" type="password" @class(['form-control', 'is-invalid' => $errors->has('password')]) autocomplete="new-password" required>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label for="password_confirmation" class="form-label">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Create my account</button>
        </form>
    @endif
</x-auth-card>
