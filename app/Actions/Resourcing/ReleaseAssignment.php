<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Releases a booking and tells the person.
 */
class ReleaseAssignment
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, int $assignmentId): void
    {
        $assignment = $this->workspace->assignment($assignmentId);

        if (! $assignment || ! AssignmentStatus::from($assignment['status'])->holdsPerson()) {
            throw ValidationException::withMessages(['assignment' => 'That booking has already been released.']);
        }

        $event = $this->workspace->event($assignment['event_id']) ?? [];
        Gate::forUser($user)->authorize('event.manage-crew', [$event]);

        Assignment::query()->whereKey($assignmentId)->update(['status' => AssignmentStatus::Released]);
        $this->workspace->forget();

        $dates = DateRange::of($assignment['start_date'], $assignment['end_date'])->format();
        $name = $this->workspace->personName($assignment['person_id']);

        activity('resourcing')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'], 'old' => ['status' => $assignment['status']], 'attributes' => ['status' => AssignmentStatus::Released->value, 'person_id' => $assignment['person_id']]])
            ->event('released')
            ->log("Released {$name} from {$event['code']}, {$dates}");

        $this->workspace->notify($assignment['person_id'], 'booking', "Released from {$event['code']}", "You are no longer needed on {$event['name']}, {$dates}.", 'my-work');
    }
}
