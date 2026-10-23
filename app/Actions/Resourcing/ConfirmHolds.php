<?php

namespace App\Actions\Resourcing;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Gate;

/**
 * A producer confirms holds: one, or every first hold on an event once it
 * is confirmed. Holds that can't be confirmed (leave, an expired document,
 * a freelancer who hasn't answered) are left as they are and listed.
 */
class ConfirmHolds
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ConfirmAssignment $confirm,
    ) {}

    /**
     * @param  list<int>  $assignmentIds
     * @return array{confirmed: int, skipped: array<string, list<string>>}
     */
    public function handle(User $user, array $assignmentIds): array
    {
        $confirmed = 0;
        $skipped = [];

        foreach ($assignmentIds as $id) {
            $assignment = $this->workspace->assignment($id);

            if (! $assignment) {
                continue;
            }

            $event = $this->workspace->event($assignment['event_id']) ?? [];
            Gate::forUser($user)->authorize('event.manage-crew', [$event]);

            $name = $this->workspace->personName($assignment['person_id']);
            $reasons = $this->confirm->blockers($id);

            if (! in_array($assignment['status'], [AssignmentStatus::FirstHold->value, AssignmentStatus::SecondHold->value], true)) {
                $reasons = ['It is not a hold.'];
            } elseif ($assignment['responded_at'] === null) {
                $reasons[] = 'They haven’t accepted the booking request yet.';
            }

            if ($reasons !== []) {
                $skipped[$name] = $reasons;

                continue;
            }

            $this->confirm->handle($id);
            $confirmed++;

            activity('resourcing')
                ->causedBy($user)
                ->withProperties(['ref' => $event['code'], 'old' => ['status' => $assignment['status']], 'attributes' => ['status' => AssignmentStatus::Confirmed->value, 'person_id' => $assignment['person_id']]])
                ->event('confirmed')
                ->log("Confirmed {$name} on {$event['code']}");

            $this->workspace->notify($assignment['person_id'], 'booking', "Confirmed on {$event['code']}", $event['name'].', '.DateRange::of($assignment['start_date'], $assignment['end_date'])->format().'. The call sheet follows nearer the time.', 'my-work');
        }

        $this->workspace->forget();

        return ['confirmed' => $confirmed, 'skipped' => $skipped];
    }

    /**
     * Every first hold on an event, for the bulk confirm after the event is
     * confirmed.
     *
     * @return list<int>
     */
    public function firstHoldsOn(int $eventId): array
    {
        return $this->workspace->assignments()
            ->filter(fn ($a) => $a['event_id'] === $eventId && $a['status'] === AssignmentStatus::FirstHold->value)
            ->keys()
            ->all();
    }
}
