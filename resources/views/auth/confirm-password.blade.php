<x-auth-card title="Confirm your password" intro="This is a security setting, so confirm your password to continue.">
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input id="password" name="password" type="password" @class(['form-control', 'is-invalid' => $errors->has('password')]) autocomplete="current-password" required autofocus>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">Confirm password</button>
    </form>
</x-auth-card>
