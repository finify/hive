<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A freelancer or employee accepts or declines a booking request. Accepting
 * confirms the booking when the event is confirmed; otherwise the hold stays.
 */
class RespondToBookingRequest
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ConfirmAssignment $confirm,
    ) {}

    public function handle(User $user, int $assignmentId, bool $accept): AssignmentStatus
    {
        $assignment = $this->workspace->assignment($assignmentId);

        if (! $assignment) {
            throw ValidationException::withMessages(['assignment' => 'That booking request no longer exists.']);
        }

        Gate::forUser($user)->authorize('assignment.respond', [$assignment]);

        $event = $this->workspace->event($assignment['event_id']);
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');

        if (! $accept) {
            $this->workspace->ledger->put('assignments', $assignmentId, ['status' => AssignmentStatus::Declined->value, 'responded_at' => $now]);
            $status = AssignmentStatus::Declined;
        } else {
            $this->workspace->ledger->put('assignments', $assignmentId, ['responded_at' => $now]);
            $this->workspace->forget();
            $status = $this->confirm->canConfirm($assignmentId)
                ? $this->confirm->handle($assignmentId)
                : AssignmentStatus::from($assignment['status']);
        }

        $this->workspace->forget();

        activity('resourcing')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'], 'old' => ['status' => $assignment['status']], 'attributes' => ['status' => $status->value]])
            ->event($accept ? 'accepted' : 'declined')
            ->log(sprintf('%s the booking request for %s', $accept ? 'Accepted' : 'Declined', $event['name']));

        $this->workspace->notify(
            $event['producer_id'], 'booking',
            sprintf('%s %s %s', $this->workspace->personName($assignment['person_id']), $accept ? 'accepted' : 'declined', $event['code']),
            $accept ? 'Booking is now '.mb_strtolower($status->label()).'.' : 'Find someone else from the crew tab.',
            'events',
        );

        return $status;
    }
}
