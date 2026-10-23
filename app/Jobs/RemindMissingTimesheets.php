<?php

namespace App\Jobs;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Support\DateRange;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Friday 14:00: reminds anyone booked on an event this week whose timesheet
 * for the week is missing or still a draft.
 */
class RemindMissingTimesheets implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(Workspace $workspace): void
    {
        $workspace->forget();
        $week = DateRange::of($workspace->today()->startOfWeek()->toDateString(), $workspace->today()->endOfWeek()->toDateString());

        $booked = $workspace->assignments()
            ->filter(fn ($a) => $a['status'] === AssignmentStatus::Confirmed->value && DateRange::of($a['start_date'], $a['end_date'])->overlaps($week))
            ->groupBy('person_id');

        foreach ($booked as $personId => $assignments) {
            $sheet = $workspace->timesheets()->first(fn ($s) => $s['person_id'] === $personId && $s['week_start'] === $week->start->toDateString());

            if ($sheet !== null && $sheet['status'] !== 'draft') {
                continue;
            }

            $codes = $assignments->map(fn ($a) => $workspace->event($a['event_id'])['code'] ?? null)->filter()->unique()->implode(', ');

            $workspace->notify((int) $personId, 'timesheet', 'Timesheet for this week',
                ($sheet === null ? 'You haven’t started' : 'You haven’t submitted')." your timesheet for {$week->format()} ({$codes}). Submit it so producers can approve it.", 'timesheets');
        }
    }
}
