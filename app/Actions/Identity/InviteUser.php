<?php

namespace App\Actions\Identity;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Hive has no public registration: people join by invitation, which expires
 * after seven days. The token is stored hashed; only the email holds it.
 */
class InviteUser
{
    public function handle(User $inviter, string $email, string $role): Invitation
    {
        Gate::forUser($inviter)->authorize('invite', User::class);

        $data = Validator::make(['email' => $email, 'role' => $role], [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
        ], [
            'email.unique' => 'Someone with this email already has a Hive account.',
        ])->validate();

        Invitation::query()->pending()->where('email', $data['email'])->update(['expires_at' => now()]);

        $token = Str::random(48);

        $invitation = Invitation::create([
            'email' => mb_strtolower($data['email']),
            'role' => $data['role'],
            'token' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays((int) config('hive.invitation_days')),
        ]);

        $invitation->setRelation('inviter', $inviter);

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($invitation, $token));

        return $invitation;
    }
}
