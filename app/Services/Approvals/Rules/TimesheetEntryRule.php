<?php

namespace App\Services\Approvals\Rules;

use App\Demo\Workspace;
use App\Models\TimesheetEntry;
use App\Services\Approvals\ApprovalRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Time on an event goes to that event's producer (rule 6); internal time to
 * the person's line manager. A producer's own time on their event goes to
 * their line manager instead.
 */
class TimesheetEntryRule implements ApprovalRule
{
    public function __construct(private readonly Workspace $workspace) {}

    public function mode(): string
    {
        return self::SINGLE;
    }

    public function label(): string
    {
        return 'Timesheet entry';
    }

    public function requester(Model $approvable): ?int
    {
        /** @var TimesheetEntry $approvable */
        return $approvable->timesheet->person_id;
    }

    public function steps(Model $approvable): array
    {
        /** @var TimesheetEntry $approvable */
        $personId = $approvable->timesheet->person_id;
        $producer = $approvable->event_id ? ($this->workspace->event($approvable->event_id)['producer_id'] ?? null) : null;
        $manager = $this->workspace->person($personId)['line_manager_id'] ?? null;

        $approver = $producer !== null && $producer !== $personId ? $producer : $manager;

        return $approver === null ? [] : [[$approver]];
    }
}
