<?php

namespace App\Policies;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Models\User;

class AssignmentPolicy
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * Freelancers and employees accept or decline their own booking requests.
     *
     * @param  array<string, mixed>  $assignment
     */
    public function respond(User $user, array $assignment): bool
    {
        return $user->can('crew.respond')
            && $assignment['person_id'] === $user->person_id
            && $assignment['responded_at'] === null
            && in_array($assignment['status'], [AssignmentStatus::FirstHold->value, AssignmentStatus::SecondHold->value], true);
    }

    /**
     * Reassigning a booking is crew management on that booking's event.
     *
     * @param  array<string, mixed>  $assignment
     */
    public function reassign(User $user, array $assignment): bool
    {
        $event = $this->workspace->event($assignment['event_id']);

        if (! $event) {
            return false;
        }

        return $user->can('crew.manage_all') || ($user->can('crew.manage') && $event['producer_id'] === $user->person_id);
    }
}
