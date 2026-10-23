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
}
