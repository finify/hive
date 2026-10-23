<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Approval;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Display;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Submits a week. Each entry not yet approved goes to its approver through
 * the approvals engine: the event's producer, or the line manager for
 * internal codes.
 */
class SubmitTimesheet
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
    ) {}

    public function handle(User $user, int $sheetId): void
    {
        $sheet = Timesheet::query()->with('entries.timesheet')->find($sheetId);

        abort_unless($sheet && $sheet->person_id === $user->person_id && $user->can('timesheets.submit'), 403);

        if (! in_array($sheet->status, [TimesheetStatus::Draft, TimesheetStatus::Returned], true)) {
            throw ValidationException::withMessages(['sheet' => 'This week has already been submitted.']);
        }

        if ($sheet->entries->isEmpty()) {
            throw ValidationException::withMessages(['sheet' => 'Add at least one entry before submitting.']);
        }

        $old = $sheet->status;

        $approvers = DB::transaction(function () use ($sheet) {
            $approvers = collect();

            foreach ($sheet->entries as $entry) {
                if ($entry->status === EntryStatus::Approved) {
                    continue;
                }

                $entry->update(['status' => EntryStatus::Pending, 'return_comment' => null]);
                $approvers = $approvers->merge($this->approvals->request($entry)->map(fn (Approval $a) => $a->approver_id));
            }

            $sheet->update(['status' => TimesheetStatus::Submitted, 'submitted_at' => now()]);

            return $approvers->unique();
        });

        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->performedOn($sheet)
            ->withProperties(['ref' => 'TS-'.$sheetId, 'old' => ['status' => $old->value], 'attributes' => ['status' => TimesheetStatus::Submitted->value]])
            ->event('submitted')
            ->log('Submitted timesheet for the week of '.Display::date($sheet->week_start));

        foreach ($approvers as $approver) {
            $this->workspace->notify($approver, 'approval', 'Timesheet to approve', $this->workspace->personName($sheet->person_id).', week of '.Display::date($sheet->week_start).'.', 'approvals');
        }
    }
}
