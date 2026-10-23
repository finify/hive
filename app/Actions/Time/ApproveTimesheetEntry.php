<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\User;
use App\Support\Display;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApproveTimesheetEntry
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly PostTimesheetCost $postCost,
    ) {}

    public function handle(User $approver, int $entryId): void
    {
        $found = $this->workspace->findEntry($entryId);

        if (! $found) {
            throw ValidationException::withMessages(['entry' => 'That timesheet entry no longer exists.']);
        }

        [$sheet, $entry] = $found;

        Gate::forUser($approver)->authorize('timesheet-entry.approve', [$sheet, $entry]);

        $after = ['status' => EntryStatus::Approved->value, 'approved_by' => $approver->person_id, 'approved_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'return_comment' => null];
        $this->workspace->ledger->put('entries', $entryId, $after);
        $this->workspace->forget();

        $cost = $this->postCost->handle($sheet['person_id'], $entryId);

        activity('time')
            ->causedBy($approver)
            ->withProperties([
                'ref' => 'TE-'.$entryId,
                'old' => ['status' => $entry['status']],
                'attributes' => ['status' => EntryStatus::Approved->value, 'cost_posted' => $cost?->format()],
            ])
            ->event('approved')
            ->log(sprintf('Approved %s’s time on %s (%s)', $this->workspace->personName($sheet['person_id']), Display::date($entry['date']), $this->label($entry)));

        [$sheet] = $this->workspace->findEntry($entryId) ?? [$sheet];

        if ($sheet['status'] === TimesheetStatus::Approved->value) {
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
