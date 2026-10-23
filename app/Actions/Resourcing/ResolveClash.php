<?php

namespace App\Actions\Resourcing;

use App\Data\Clash;
use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a clash by moving one booking's clashing days to someone else.
 * The original booking is trimmed to the days that do not clash, the
 * replacement is booked for the clashing days, and both people are told.
 */
class ResolveClash
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, string $clashKey, int $assignmentId, int $replacementId): int
    {
        $clash = $this->workspace->findClash($clashKey);

        if (! $clash) {
            throw ValidationException::withMessages(['clash' => 'This clash has already been resolved.']);
        }

        $assignment = $this->workspace->assignment($assignmentId);

        if (! $assignment || ! $this->belongsToClash($clash, $assignmentId)) {
            throw ValidationException::withMessages(['assignment' => 'Choose one of the two clashing bookings to move.']);
        }

        Gate::forUser($user)->authorize('assignment.reassign', [$assignment]);

        $replacement = $this->workspace->person($replacementId);
        $role = $this->workspace->crewCalls()->get($assignment['crew_call_id'])['role_name'] ?? null;
        $available = $this->workspace->availablePeople((string) $role, $clash->dates)->pluck('person.id');

        if (! $replacement || ! $available->contains($replacementId)) {
            throw ValidationException::withMessages(['replacement' => 'That person is no longer free on those dates.']);
        }

        $event = $this->workspace->event($assignment['event_id']);
        $original = DateRange::of($assignment['start_date'], $assignment['end_date']);
        $before = ['person_id' => $assignment['person_id'], 'start_date' => $assignment['start_date'], 'end_date' => $assignment['end_date']];

        // Trim the original booking to the days either side of the clash.
        $kept = [];

        if ($original->start->lessThan($clash->dates->start)) {
            $kept[] = DateRange::of($original->start->toDateString(), $clash->dates->start->subDay()->toDateString());
        }

        if ($original->end->greaterThan($clash->dates->end)) {
            $kept[] = DateRange::of($clash->dates->end->addDay()->toDateString(), $original->end->toDateString());
        }

        if ($kept === []) {
            $this->workspace->ledger->put('assignments', $assignmentId, ['status' => AssignmentStatus::Released->value]);
        } else {
            $this->workspace->ledger->put('assignments', $assignmentId, ['start_date' => $kept[0]->start->toDateString(), 'end_date' => $kept[0]->end->toDateString()]);

            if (isset($kept[1])) {
                $this->book($assignment, $assignment['person_id'], $kept[1], AssignmentStatus::from($assignment['status']));
            }
        }

        $newId = $this->book($assignment, $replacementId, $clash->dates, AssignmentStatus::Confirmed);
        $this->workspace->forget();

        $dates = $clash->dates->format();
        $fromName = $this->workspace->personName($assignment['person_id']);
        $toName = $this->workspace->personName($replacementId);

        activity('resourcing')
            ->causedBy($user)
            ->withProperties([
                'ref' => $event['code'],
                'old' => $before,
                'attributes' => ['moved_to_person_id' => $replacementId, 'dates' => $dates, 'new_assignment_id' => $newId],
            ])
            ->event('clash-resolved')
            ->log("Resolved clash on {$event['code']}: moved {$dates} from {$fromName} to {$toName}");

        $this->workspace->notify($assignment['person_id'], 'booking', "Released from {$event['code']} on {$dates}", "You are no longer needed on {$event['name']} for {$dates}. Your other days are unchanged.", 'my-work');
        $this->workspace->notify($replacementId, 'booking', "Booked on {$event['code']}", "{$event['name']}, {$dates}, as ".($role ?? 'crew').'.', 'my-work');

        return $newId;
    }

    private function belongsToClash(Clash $clash, int $assignmentId): bool
    {
        foreach ([$clash->first, $clash->second] as $booking) {
            if ($booking->kind === 'assignment' && (int) $booking->id === $assignmentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function book(array $template, int $personId, DateRange $dates, AssignmentStatus $status): int
    {
        $id = $this->workspace->ledger->nextId('assignments', 10_000);

        $this->workspace->ledger->push('new_assignments', [
            'id' => $id,
            'crew_call_id' => $template['crew_call_id'],
            'event_id' => $template['event_id'],
            'person_id' => $personId,
            'start_date' => $dates->start->toDateString(),
            'end_date' => $dates->end->toDateString(),
            'status' => $status->value,
            'agreed_rate_minor' => $this->workspace->rateBook($personId)->rateOn($dates->start)?->minor,
            'currency' => 'GBP',
            'clash_override_reason' => null,
            'requested_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'responded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        return $id;
    }
}
