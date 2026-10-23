<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\User;
use App\Support\Time\WorkingTimeRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds a line to the person's own weekly timesheet, starting the week's
 * timesheet if there isn't one. Locked periods reject new time.
 */
class AddTimesheetEntry
{
    public const INTERNAL_CODES = ['Office', 'Pitch', 'Training', 'Travel'];

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the new entry
     */
    public function handle(User $user, array $input): array
    {
        abort_unless($user->can('timesheets.submit') && $user->person_id !== null, 403);

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
            throw ValidationException::withMessages(['date' => 'Time up to '.CarbonImmutable::parse((string) $this->workspace->lockedUntil())->format('j M Y').' is locked after payroll. Ask finance to unlock it.']);
        }

        $eventId = null;
        $code = null;

        if (str_starts_with($data['work'], 'event:')) {
            $eventId = (int) substr($data['work'], 6);

            if (! $this->workspace->isOnEvent($eventId, $user->person_id)) {
                throw ValidationException::withMessages(['work' => 'You can only log time on events you are booked on.']);
            }
        } else {
            Validator::make(['work' => $data['work']], ['work' => [Rule::in(self::INTERNAL_CODES)]])->validate();
            $code = $data['work'];
        }

        $weekStart = CarbonImmutable::parse($data['date'])->startOfWeek()->toDateString();
        $sheet = $this->workspace->timesheets()->first(fn ($s) => $s['person_id'] === $user->person_id && $s['week_start'] === $weekStart);

        if ($sheet && ! in_array($sheet['status'], [TimesheetStatus::Draft->value, TimesheetStatus::Returned->value], true)) {
            throw ValidationException::withMessages(['date' => 'That week has been submitted. Ask your approver to return it if something is missing.']);
        }

        if (! $sheet) {
            $sheet = [
                'id' => $this->workspace->ledger->nextId('timesheets', 10_000),
                'person_id' => $user->person_id, 'week_start' => $weekStart, 'status' => TimesheetStatus::Draft->value,
                'submitted_at' => null, 'locked_at' => null, 'entries' => [],
            ];
            $this->workspace->ledger->push('new_timesheets', $sheet);
        }

        $minutes = WorkingTimeRules::workedMinutes($data['start_time'], $data['end_time'], (int) $data['break_minutes']);
        $entry = [
            'id' => $this->workspace->ledger->nextId('entries', 100_000),
            'date' => $data['date'], 'event_id' => $eventId, 'internal_code' => $code,
            'phase' => $eventId ? $this->phaseOn($eventId, $data['date']) : null,
            'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'break_minutes' => (int) $data['break_minutes'],
            'hours' => round($minutes / 60, 2), 'days' => $minutes >= 360 ? 1 : 0.5,
            'notes' => $data['notes'] ?? null, 'status' => EntryStatus::Pending->value,
            'approved_by' => null, 'approved_at' => null, 'return_comment' => null,
        ];

        $this->workspace->ledger->push('new_entries', ['timesheet_id' => $sheet['id'], 'entry' => $entry]);
        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->withProperties(['ref' => 'TE-'.$entry['id'], 'attributes' => ['date' => $entry['date'], 'hours' => $entry['hours']]])
            ->event('created')
            ->log('Added '.$entry['hours'].' hours on '.CarbonImmutable::parse($entry['date'])->format('j M Y'));

        return $entry;
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
