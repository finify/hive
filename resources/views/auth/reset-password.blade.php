<x-auth-card title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">
        <div class="mb-3">
            <label for="email" class="form-label">Work email</label>
            <input id="email" name="email" type="email" value="{{ old('email', request('email')) }}" @class(['form-control', 'is-invalid' => $errors->has('email')]) required>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <input id="password" name="password" type="password" @class(['form-control', 'is-invalid' => $errors->has('password')]) autocomplete="new-password" required>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Save new password</button>
    </form>
</x-auth-card>
