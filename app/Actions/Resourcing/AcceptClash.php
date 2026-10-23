<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Keeps both bookings and records why, for example a technician covering a
 * morning build and an evening show in the same city.
 */
class AcceptClash
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, string $clashKey, int $assignmentId, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:500']], [
            'reason.required' => 'Record why both bookings can stand.',
            'reason.min' => 'Record why both bookings can stand, in a sentence.',
        ])->validate();

        $clash = $this->workspace->findClash($clashKey);
        $assignment = $this->workspace->assignment($assignmentId);

        if (! $clash || ! $assignment) {
            throw ValidationException::withMessages(['clash' => 'This clash has already been resolved.']);
        }

        Gate::forUser($user)->authorize('assignment.reassign', [$assignment]);

        $this->workspace->ledger->put('assignments', $assignmentId, ['clash_override_reason' => $reason]);
        $this->workspace->forget();

        activity('resourcing')
            ->causedBy($user)
            ->withProperties(['ref' => $this->workspace->event($assignment['event_id'])['code'] ?? null, 'attributes' => ['clash_override_reason' => $reason]])
            ->event('clash-accepted')
            ->log('Kept both bookings for '.$this->workspace->personName($assignment['person_id']).': '.$reason);
    }
}
