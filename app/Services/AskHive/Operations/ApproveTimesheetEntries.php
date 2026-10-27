<?php

namespace App\Services\AskHive\Operations;

use App\Actions\Time\ApproveTimesheetEntry as Action;
use App\Models\User;
use App\Support\Display;
use Illuminate\Validation\ValidationException;

class ApproveTimesheetEntries extends Support
{
    public static function name(): string
    {
        return 'approve_timesheet_entries';
    }

    public function description(): string
    {
        return 'Approve timesheet entries waiting for the user (ids from list_pending_timesheet_entries). Never propose approving flagged entries without the user asking for those specifically.';
    }

    public function schema(): array
    {
        return self::object(['entry_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50]], ['entry_ids']);
    }

    public function offeredTo(User $user): bool
    {
        return $user->can('timesheets.approve');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function ids(array $input): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) ($input['entry_ids'] ?? []), 'is_numeric'))));

        return $ids ?: throw ValidationException::withMessages(['entry_ids' => 'Say which entries.']);
    }

    public function summary(User $user, array $input): string
    {
        $lines = array_map(function (int $id) {
            $found = $this->workspace->findEntry($id);

            return $found ? $this->workspace->personName($found[0]['person_id']).', '.Display::date($found[1]['date']).' ('.Display::hours((float) $found[1]['hours']).')' : "entry {$id}";
        }, $this->ids($input));

        return 'Approve '.count($lines).' timesheet '.(count($lines) === 1 ? 'entry' : 'entries').': '.implode('; ', $lines);
    }

    public function perform(User $user, array $input): string
    {
        $action = app(Action::class);

        foreach ($this->ids($input) as $id) {
            $action->handle($user, $id);
        }

        return 'Approved. Freelance time is now cost on its event.';
    }
}
