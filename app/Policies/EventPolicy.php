<?php

namespace App\Policies;

use App\Enums\EventStage;
use App\Models\User;

class EventPolicy
{
    /**
     * Producers move their own events; the operations director and admins
     * move any. Finance may only reconcile.
     *
     * @param  array<string, mixed>  $event
     */
    public function changeStage(User $user, array $event, EventStage $to): bool
    {
        if ($to === EventStage::Reconciled && $user->can('events.reconcile') && $event['stage'] === EventStage::Wrap->value) {
            return true;
        }

        if (! $user->can('events.change_stage')) {
            return false;
        }

        return $user->can('events.edit_all') || $event['producer_id'] === $user->person_id;
    }

    /**
     * Editing details, phases, team, tasks and files: the producer on their
     * own events, or anyone who edits all events.
     *
     * @param  array<string, mixed>  $event
     */
    public function edit(User $user, array $event): bool
    {
        return $user->can('events.edit_all') || ($user->can('events.edit') && $event['producer_id'] !== null && $event['producer_id'] === $user->person_id);
    }

    /**
     * Ticking a task off: whoever may edit the event, or the person it is
     * assigned to.
     *
     * @param  array<string, mixed>  $event
     */
    public function updateTask(User $user, array $event, ?int $assigneeId): bool
    {
        return $this->edit($user, $event) || ($assigneeId !== null && $assigneeId === $user->person_id);
    }
}
