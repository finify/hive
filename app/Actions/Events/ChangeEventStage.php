<?php

namespace App\Actions\Events;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Enums\EventStage;
use App\Models\Assignment;
use App\Models\Event;
use App\Models\EventStageChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeEventStage
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApplyTaskTemplate $templates,
    ) {}

    /**
     * Why an event cannot move to a stage. Empty when the move is allowed.
     *
     * @return list<string>
     */
    public function blockers(int $eventId, EventStage $to): array
    {
        $event = $this->workspace->event($eventId);

        if (! $event) {
            return ['That event no longer exists.'];
        }

        $from = EventStage::from($event['stage']);

        if ($from === $to) {
            return ["It is already at {$to->label()}."];
        }

        if (! $from->canMoveTo($to)) {
            return ["{$from->label()} cannot move to {$to->label()}. Events move one stage at a time."];
        }

        $reasons = [];

        if ($to === EventStage::ProposalSent) {
            if (! $event['client_id']) {
                $reasons[] = 'Add the client.';
            }

            if (! $event['start_date'] || ! $event['end_date']) {
                $reasons[] = 'Add outline dates.';
            }

            if (! $event['client_fee_minor']) {
                $reasons[] = 'Add an estimated budget.';
            }
        }

        if ($to === EventStage::Confirmed) {
            if (! ($event['contract_signed'] ?? false)) {
                $reasons[] = 'Upload the signed contract.';
            }

            if (! $event['client_fee_minor']) {
                $reasons[] = 'Record the agreed client fee.';
            }
        }

        if ($to === EventStage::Reconciled) {
            $reasons = array_merge($reasons, $this->workspace->reconcileBlockers($eventId));
        }

        return $reasons;
    }

    public function handle(User $user, int $eventId, EventStage $to, ?string $note = null): void
    {
        $event = $this->workspace->event($eventId);

        if (! $event) {
            throw ValidationException::withMessages(['stage' => 'That event no longer exists.']);
        }

        Gate::forUser($user)->authorize('event.change-stage', [$event, $to]);

        $blockers = $this->blockers($eventId, $to);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['stage' => $blockers]);
        }

        $from = $event['stage'];
        $tasksAdded = 0;

        DB::transaction(function () use ($user, $eventId, $from, $to, $note, &$tasksAdded) {
            $model = Event::query()->lockForUpdate()->findOrFail($eventId);
            $model->update(['stage' => $to]);

            EventStageChange::query()->create([
                'event_id' => $eventId, 'from_stage' => $from, 'to_stage' => $to,
                'changed_by' => $user->person_id, 'note' => $note, 'changed_at' => now(),
            ]);

            // A confirmed event gets the standard task list if it has none yet.
            if ($to === EventStage::Confirmed && ! $model->tasks()->exists()) {
                $tasksAdded = $this->templates->apply($model);
            }
        });

        $released = $to === EventStage::Cancelled ? $this->releaseBookings($eventId) : 0;
        $this->workspace->forget();

        activity('events')
            ->causedBy($user)
            ->withProperties(['ref' => $event['code'], 'old' => ['stage' => $from], 'attributes' => ['stage' => $to->value, 'note' => $note, 'released_bookings' => $released, 'tasks_added' => $tasksAdded]])
            ->event('stage-changed')
            ->log("Moved {$event['name']} to {$to->label()}".($released ? " and released {$released} bookings" : ''));
    }

    /**
     * Cancelling releases every live booking and tells each person.
     */
    private function releaseBookings(int $eventId): int
    {
        $event = $this->workspace->event($eventId);
        $count = 0;

        foreach ($this->workspace->assignments()->where('event_id', $eventId) as $assignment) {
            if (! AssignmentStatus::from($assignment['status'])->holdsPerson()) {
                continue;
            }

            Assignment::query()->whereKey($assignment['id'])->update(['status' => AssignmentStatus::Released]);
            $this->workspace->notify($assignment['person_id'], 'booking', "Released from {$event['code']}", "{$event['name']} has been cancelled. Your booking is released.", 'my-work');
            $count++;
        }

        $financeLead = $this->workspace->departments()->firstWhere('name', 'Finance')['head_person_id'] ?? null;

        if ($count > 0 && $financeLead) {
            $this->workspace->notify($financeLead, 'billing', "Record cancellation fees for {$event['code']}", "{$event['name']} was cancelled. Check the contract for cancellation fees.", 'events');
        }

        return $count;
    }
}
