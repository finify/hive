<?php

namespace App\Actions\Identity;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AcceptInvitation
{
    use PasswordValidationRules;

    public static function find(string $token): ?Invitation
    {
        return Invitation::query()->with('inviter')->where('token', hash('sha256', $token))->first();
    }

    /**
     * @param  array{name?: string, password?: string, password_confirmation?: string}  $input
     */
    public function handle(string $token, array $input): User
    {
        $invitation = self::find($token);

        if (! $invitation || ! $invitation->isPending()) {
            throw ValidationException::withMessages(['token' => 'This invitation has expired or has already been used. Ask for a new one.']);
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($invitation, $data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($invitation->role->value);

            $invitation->update(['accepted_at' => now()]);

            activity('identity')
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['attributes' => ['role' => $invitation->role->value]])
                ->event('invitation-accepted')
                ->log("Accepted invitation as {$invitation->role->label()}");

            return $user;
        });
    }
}
