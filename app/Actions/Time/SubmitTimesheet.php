<?php

namespace App\Actions\Time;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\User;
use App\Support\Display;
use Illuminate\Validation\ValidationException;

/**
 * Submits a week. Each entry goes to the producer of its event, or to the
 * line manager for internal codes.
 */
class SubmitTimesheet
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(User $user, int $sheetId): void
    {
        $sheet = $this->workspace->timesheets()->get($sheetId);

        abort_unless($sheet && $sheet['person_id'] === $user->person_id && $user->can('timesheets.submit'), 403);

        if (! in_array($sheet['status'], [TimesheetStatus::Draft->value, TimesheetStatus::Returned->value], true)) {
            throw ValidationException::withMessages(['sheet' => 'This week has already been submitted.']);
        }

        if ($sheet['entries'] === []) {
            throw ValidationException::withMessages(['sheet' => 'Add at least one entry before submitting.']);
        }

        foreach ($sheet['entries'] as $entry) {
            if ($entry['status'] !== EntryStatus::Approved->value) {
                $this->workspace->ledger->put('entries', $entry['id'], ['status' => EntryStatus::Pending->value, 'return_comment' => null]);
            }
        }

        $this->workspace->ledger->put('timesheets', $sheetId, ['status' => TimesheetStatus::Submitted->value, 'submitted_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]);
        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->withProperties(['ref' => 'TS-'.$sheetId, 'old' => ['status' => $sheet['status']], 'attributes' => ['status' => TimesheetStatus::Submitted->value]])
            ->event('submitted')
            ->log('Submitted timesheet for the week of '.Display::date($sheet['week_start']));

        $approvers = Rows::of($sheet['entries'])
            ->map(fn ($e) => $e['event_id'] ? ($this->workspace->event($e['event_id'])['producer_id'] ?? null) : ($this->workspace->person($sheet['person_id'])['line_manager_id'] ?? null))
            ->filter()->unique();

        foreach ($approvers as $approver) {
            $this->workspace->notify($approver, 'approval', 'Timesheet to approve', $this->workspace->personName($sheet['person_id']).', week of '.Display::date($sheet['week_start']).'.', 'approvals');
        }
    }
}
