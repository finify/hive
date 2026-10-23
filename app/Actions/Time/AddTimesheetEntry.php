<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Time\WorkingTimeRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes or removes a line on the person's own weekly timesheet,
 * starting the week's timesheet if there isn't one. Only draft and returned
 * weeks can change, and locked periods reject any change (rule 9).
 */
class AddTimesheetEntry
{
    public const INTERNAL_CODES = ['Office', 'Pitch', 'Training', 'Travel'];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the new entry
     */
    public function handle(User $user, array $input): array
    {
        abort_unless($user->can('timesheets.submit') && $user->person_id !== null, 403);

        $data = $this->validate($user, $input);
        $weekStart = CarbonImmutable::parse($data['date'])->startOfWeek()->toDateString();
        $sheet = Timesheet::query()->where('person_id', $user->person_id)->whereDate('week_start', $weekStart)->first();

        if ($sheet && ! in_array($sheet->status, [TimesheetStatus::Draft, TimesheetStatus::Returned], true)) {
            throw ValidationException::withMessages(['date' => 'That week has been submitted. Ask your approver to return it if something is missing.']);
        }

        $entry = DB::transaction(function () use ($sheet, $user, $weekStart, $data) {
            $sheet ??= Timesheet::query()->create(['person_id' => $user->person_id, 'week_start' => $weekStart, 'status' => TimesheetStatus::Draft]);

            return $sheet->entries()->create($this->attributes($data) + ['status' => EntryStatus::Pending]);
        });

        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->performedOn($entry)
            ->withProperties(['ref' => 'TE-'.$entry->id, 'attributes' => ['date' => $data['date'], 'hours' => (float) $entry->hours, 'work' => $data['work']]])
            ->event('created')
            ->log('Added '.(float) $entry->hours.' hours on '.CarbonImmutable::parse($data['date'])->format('j M Y'));

        return $entry->toWorkspaceArray();
    }

    /**
     * Changes an entry on a draft or returned week. A returned entry goes
     * back for approval when the week is resubmitted.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, int $entryId, array $input): void
    {
        $entry = $this->ownEditable($user, $entryId);
        $data = $this->validate($user, $input);

        if (CarbonImmutable::parse($data['date'])->startOfWeek()->toDateString() !== $entry->timesheet->week_start->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Keep the date in the same week, or delete this entry and add it to the other week.']);
        }

        $old = $entry->toWorkspaceArray();
        $entry->update($this->attributes($data));
        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->performedOn($entry)
            ->withProperties([
                'ref' => 'TE-'.$entry->id,
                'old' => array_intersect_key($old, array_flip(['date', 'start_time', 'end_time', 'break_minutes', 'hours', 'event_id', 'internal_code'])),
                'attributes' => array_intersect_key($entry->toWorkspaceArray(), array_flip(['date', 'start_time', 'end_time', 'break_minutes', 'hours', 'event_id', 'internal_code'])),
            ])
            ->event('updated')
            ->log('Changed time on '.$entry->date->format('j M Y'));
    }

    public function delete(User $user, int $entryId): void
    {
        $entry = $this->ownEditable($user, $entryId);
        $before = $entry->toWorkspaceArray();
        $this->approvals->withdraw($entry);
        $entry->delete();
        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->withProperties(['ref' => 'TE-'.$entryId, 'old' => array_intersect_key($before, array_flip(['date', 'hours', 'event_id', 'internal_code']))])
            ->event('deleted')
            ->log('Removed '.$before['hours'].' hours on '.CarbonImmutable::parse($before['date'])->format('j M Y'));
    }

    private function ownEditable(User $user, int $entryId): TimesheetEntry
    {
        $entry = TimesheetEntry::query()->with('timesheet')->findOrFail($entryId);
        abort_unless($entry->timesheet->person_id === $user->person_id, 403);

        if (! in_array($entry->timesheet->status, [TimesheetStatus::Draft, TimesheetStatus::Returned], true) || $entry->status === EntryStatus::Approved) {
            throw ValidationException::withMessages(['entry' => 'Only time on a draft or returned week, not yet approved, can change.']);
        }

        if ($this->workspace->isLocked($entry->date->toDateString())) {
            throw ValidationException::withMessages(['entry' => $this->lockedMessage()]);
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(User $user, array $input): array
    {
        $data = Validator::make($input, [
            'date' => ['required', 'date'],
            'work' => ['required', 'string'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [
            'work.required' => 'Choose the event or internal code you worked on.',
            'end_time.different' => 'The finish time must differ from the start time.',
        ])->validate();

        if ($this->workspace->isLocked($data['date'])) {
            throw ValidationException::withMessages(['date' => $this->lockedMessage()]);
        }

        if (str_starts_with($data['work'], 'event:')) {
            $eventId = (int) substr($data['work'], 6);

            if (! $this->workspace->isOnEvent($eventId, $user->person_id)) {
                throw ValidationException::withMessages(['work' => 'You can only log time on events you are booked on.']);
            }

            $data['event_id'] = $eventId;
            $data['internal_code'] = null;
        } else {
            Validator::make(['work' => $data['work']], ['work' => [Rule::in(self::INTERNAL_CODES)]])->validate();
            $data['event_id'] = null;
            $data['internal_code'] = $data['work'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $minutes = WorkingTimeRules::workedMinutes($data['start_time'], $data['end_time'], (int) $data['break_minutes']);

        return [
            'date' => $data['date'], 'event_id' => $data['event_id'], 'internal_code' => $data['internal_code'],
            'phase' => $data['event_id'] ? $this->phaseOn($data['event_id'], $data['date']) : null,
            'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'break_minutes' => (int) $data['break_minutes'],
            'hours' => round($minutes / 60, 2), 'days' => $minutes >= 360 ? 1 : 0.5, 'notes' => $data['notes'] ?? null,
        ];
    }

    private function lockedMessage(): string
    {
        return 'Time up to '.CarbonImmutable::parse((string) $this->workspace->lockedUntil())->format('j M Y').' is locked after payroll. Ask finance to unlock it.';
    }

    private function phaseOn(int $eventId, string $date): ?string
    {
        foreach ($this->workspace->event($eventId)['phases'] ?? [] as $phase) {
            if ($phase['start_date'] <= $date && $date <= $phase['end_date']) {
                return $phase['type'];
            }
        }

        return null;
    }
}
