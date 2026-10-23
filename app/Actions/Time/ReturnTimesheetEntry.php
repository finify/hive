<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Models\User;
use App\Support\Display;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReturnTimesheetEntry
{
    public function __construct(private readonly Workspace $workspace) {}

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

        $this->workspace->ledger->put('entries', $entryId, ['status' => EntryStatus::Returned->value, 'return_comment' => $comment, 'approved_by' => null, 'approved_at' => null]);
        $this->workspace->forget();

        activity('time')
            ->causedBy($approver)
            ->withProperties(['ref' => 'TE-'.$entryId, 'old' => ['status' => $entry['status']], 'attributes' => ['status' => EntryStatus::Returned->value, 'comment' => $comment]])
            ->event('returned')
            ->log(sprintf('Returned %s’s time on %s', $this->workspace->personName($sheet['person_id']), Display::date($entry['date'])));

        $this->workspace->notify($sheet['person_id'], 'timesheet', 'Timesheet returned', Display::date($entry['date']).': '.$comment, 'timesheets');
    }
}
