<?php

namespace App\Policies;

use App\Enums\EntryStatus;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;

/**
 * Entries on an event are approved by that event's producer; entries on an
 * internal code by the person's line manager (see TimesheetEntryRule), or
 * by whoever they have delegated to. Nobody approves their own time.
 */
class TimesheetEntryPolicy
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * @param  array<string, mixed>  $sheet
     * @param  array<string, mixed>  $entry
     */
    public function approve(User $user, array $sheet, array $entry): bool
    {
        if ($user->person_id === null || $sheet['person_id'] === $user->person_id) {
            return false;
        }

        if ($entry['status'] !== EntryStatus::Pending->value || in_array($sheet['status'], ['draft', 'locked'], true)) {
            return false;
        }

        $model = TimesheetEntry::query()->find($entry['id']);

        return $model !== null && $this->approvals->decidableBy($user->person_id, $model) !== null;
    }
}
