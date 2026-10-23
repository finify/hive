<x-auth-card title="Reset your password" intro="We’ll email you a link to choose a new password.">
    @if (session('status'))
        <div class="callout mb-3" role="status"><i class="bi bi-envelope-check" aria-hidden="true"></i><div>{{ session('status') }}</div></div>
    @endif
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Work email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" @class(['form-control', 'is-invalid' => $errors->has('email')]) required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">Email reset link</button>
    </form>
    <a href="{{ route('login') }}" class="small link d-inline-block mt-3">Back to sign in</a>
</x-auth-card>
