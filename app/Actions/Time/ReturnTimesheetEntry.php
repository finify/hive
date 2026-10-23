<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Display;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sends an entry back with a comment so the person can fix it. Time in a
 * locked period can't be returned (rule 9).
 */
class ReturnTimesheetEntry
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
    ) {}

    public function handle(User $approver, int $entryId, string $comment): void
    {
        Validator::make(['comment' => $comment], ['comment' => ['required', 'string', 'min:5', 'max:500']], [
            'comment.required' => 'Say what needs changing so they can fix it.',
            'comment.min' => 'Say what needs changing so they can fix it.',
        ])->validate();

        $found = $this->workspace->findEntry($entryId);

        if (! $found) {
            throw ValidationException::withMessages(['entry' => 'That timesheet entry no longer exists.']);
        }

        [$sheet, $entry] = $found;

        Gate::forUser($approver)->authorize('timesheet-entry.approve', [$sheet, $entry]);

        if ($this->workspace->isLocked($entry['date'])) {
            throw ValidationException::withMessages(['comment' => 'This time is in a locked payroll period. Ask finance to unlock it first.']);
        }

        $model = TimesheetEntry::query()->with('timesheet')->findOrFail($entryId);

        DB::transaction(function () use ($approver, $model, $comment) {
            $this->approvals->decide((int) $approver->person_id, $model, false, $comment);
            $model->update(['status' => EntryStatus::Returned, 'return_comment' => $comment, 'approved_by' => null, 'approved_at' => null]);
            $model->timesheet->syncStatus();
        });

        $this->workspace->forget();

        activity('time')
            ->causedBy($approver)
            ->performedOn($model)
            ->withProperties(['ref' => 'TE-'.$entryId, 'old' => ['status' => $entry['status']], 'attributes' => ['status' => EntryStatus::Returned->value, 'comment' => $comment]])
            ->event('returned')
            ->log(sprintf('Returned %s’s time on %s', $this->workspace->personName($sheet['person_id']), Display::date($entry['date'])));

        $this->workspace->notify($sheet['person_id'], 'timesheet', 'Timesheet returned', Display::date($entry['date']).': '.$comment, 'timesheets');
    }
}
