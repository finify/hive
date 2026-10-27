<?php

namespace App\Actions\Time;

use App\Actions\Leave\AccrueToil;
use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Display;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Approves one entry. Freelancer time on an event becomes an Actual crew
 * cost at the day rate in force that day (rule 7); an employee's weekend or
 * bank holiday on an event earns TOIL. The week is Approved once every
 * entry is.
 */
class ApproveTimesheetEntry
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
        private readonly PostTimesheetCost $postCost,
        private readonly AccrueToil $toil,
    ) {}

    public function handle(User $approver, int $entryId): void
    {
        $found = $this->workspace->findEntry($entryId);

        if (! $found) {
            throw ValidationException::withMessages(['entry' => 'That timesheet entry no longer exists.']);
        }

        [$sheet, $entry] = $found;

        Gate::forUser($approver)->authorize('timesheet-entry.approve', [$sheet, $entry]);

        $model = TimesheetEntry::query()->with('timesheet')->findOrFail($entryId);

        $outcome = DB::transaction(function () use ($approver, $model) {
            $outcome = $this->approvals->decide((int) $approver->person_id, $model, true);

            if ($outcome === ApprovalStatus::Approved) {
                $model->update(['status' => EntryStatus::Approved, 'approved_by' => $approver->person_id, 'approved_at' => now(), 'return_comment' => null]);
                $model->timesheet->syncStatus();
            }

            return $outcome;
        });

        $this->workspace->forget();
        $cost = $outcome === ApprovalStatus::Approved ? $this->postCost->handle($sheet['person_id'], $entryId) : null;

        if ($outcome === ApprovalStatus::Approved) {
            $this->toil->forEntry($entryId, $approver);
        }

        activity('time')
            ->causedBy($approver)
            ->performedOn($model)
            ->withProperties([
                'ref' => 'TE-'.$entryId,
                'old' => ['status' => $entry['status']],
                'attributes' => ['status' => $outcome === ApprovalStatus::Approved ? EntryStatus::Approved->value : $entry['status'], 'cost_posted' => $cost?->format()],
            ])
            ->event('approved')
            ->log(sprintf('Approved %s’s time on %s (%s)', $this->workspace->personName($sheet['person_id']), Display::date($entry['date']), $this->label($entry)));

        if ($model->timesheet->refresh()->status === TimesheetStatus::Approved) {
            $this->workspace->notify($sheet['person_id'], 'timesheet', 'Timesheet approved', 'Week of '.Display::date($sheet['week_start']).' is fully approved.', 'timesheets');
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function label(array $entry): string
    {
        return $entry['event_id'] ? ($this->workspace->event($entry['event_id'])['code'] ?? 'event') : $entry['internal_code'];
    }
}
