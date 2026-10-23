<?php

namespace App\Policies;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Models\User;

/**
 * Entries on an event are approved by that event's producer; entries on an
 * internal code by the person's line manager. Nobody approves their own time.
 */
class TimesheetEntryPolicy
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $sheet
     * @param  array<string, mixed>  $entry
     */
    public function approve(User $user, array $sheet, array $entry): bool
    {
        if (! $user->can('timesheets.approve') || $user->person_id === null) {
            return false;
        }

        if ($sheet['person_id'] === $user->person_id) {
            return false;
        }

        if ($entry['status'] !== EntryStatus::Pending->value || $sheet['status'] === 'draft' || $sheet['status'] === 'locked') {
            return false;
        }

        return $this->approverFor($sheet, $entry) === $user->person_id;
    }

    /**
     * The person who approves this entry.
     *
     * @param  array<string, mixed>  $sheet
     * @param  array<string, mixed>  $entry
     */
    public function approverFor(array $sheet, array $entry): ?int
    {
        if ($entry['event_id']) {
            return $this->workspace->event($entry['event_id'])['producer_id'] ?? null;
        }

        return $this->workspace->person($sheet['person_id'])['line_manager_id'] ?? null;
    }
}
