<?php

namespace App\Actions\Time;

use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\PeriodLock;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Finance closes a payroll period. Fully approved weeks inside it become
 * Locked; nothing in it can be added, changed or returned until finance
 * unlocks it with a reason, which is logged (rule 9).
 */
class LockPeriod
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array{period_start?: mixed, period_end?: mixed}  $input
     * @return array{lock: PeriodLock, locked_weeks: int, pending_entries: int}
     */
    public function lock(User $user, array $input): array
    {
        abort_unless($user->can('timesheets.lock'), 403);

        $data = Validator::make($input, [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:'.$this->workspace->today()->toDateString()],
        ], ['period_end.before_or_equal' => 'You can only lock time up to today.'])->validate();

        $overlaps = $this->workspace->periodLocks()->contains(fn ($l) => $l['start'] <= $data['period_end'] && $data['period_start'] <= $l['end']);

        if ($overlaps) {
            throw ValidationException::withMessages(['period_start' => 'Part of that period is already locked.']);
        }

        $range = DateRange::of($data['period_start'], $data['period_end']);

        $result = DB::transaction(function () use ($user, $data) {
            $lock = PeriodLock::query()->create([
                'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'locked_by' => $user->person_id, 'locked_at' => now(), 'reason' => 'Payroll run',
            ]);

            $sheets = Timesheet::query()->with('entries')
                ->whereIn('status', [TimesheetStatus::Approved])
                ->get()
                ->filter(fn (Timesheet $t) => $t->entries->isNotEmpty() && $t->entries->every(fn ($e) => $e->date->toDateString() >= $data['period_start'] && $e->date->toDateString() <= $data['period_end']));

            foreach ($sheets as $sheet) {
                $sheet->update(['status' => TimesheetStatus::Locked, 'locked_at' => now()]);
            }

            $pending = Timesheet::query()->with('entries')->whereNotIn('status', [TimesheetStatus::Draft])->get()
                ->flatMap(fn (Timesheet $t) => $t->entries)
                ->filter(fn ($e) => $e->status !== EntryStatus::Approved && $e->date->toDateString() >= $data['period_start'] && $e->date->toDateString() <= $data['period_end'])
                ->count();

            return ['lock' => $lock, 'locked_weeks' => $sheets->count(), 'pending_entries' => $pending];
        });

        $this->workspace->forget();

        activity('time')
            ->causedBy($user)
            ->performedOn($result['lock'])
            ->withProperties(['ref' => 'PL-'.$result['lock']->id, 'attributes' => ['period' => $range->format(), 'locked_weeks' => $result['locked_weeks'], 'pending_entries' => $result['pending_entries']]])
            ->event('period-locked')
            ->log("Locked timesheets for {$range->format()}");

        return $result;
    }

    public function unlock(User $user, int $lockId, string $reason): void
    {
        abort_unless($user->can('timesheets.lock'), 403);

        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:500']], [
            'reason.required' => 'Record why the period is being unlocked.',
            'reason.min' => 'Record why the period is being unlocked, in a sentence.',
        ])->validate();

        $lock = PeriodLock::query()->active()->findOrFail($lockId);
        $start = $lock->period_start->toDateString();
        $end = $lock->period_end->toDateString();

        DB::transaction(function () use ($lock, $user, $reason, $start, $end) {
            $lock->update(['unlocked_by' => $user->person_id, 'unlocked_at' => now(), 'reason' => $reason]);

            $sheets = Timesheet::query()->with('entries')->where('status', TimesheetStatus::Locked)->get()
                ->filter(fn (Timesheet $t) => $t->entries->every(fn ($e) => $e->date->toDateString() >= $start && $e->date->toDateString() <= $end));

            foreach ($sheets as $sheet) {
                $sheet->update(['status' => TimesheetStatus::Submitted, 'locked_at' => null]);
                $sheet->syncStatus();
            }
        });

        $this->workspace->forget();
        $range = DateRange::of($start, $end)->format();

        activity('time')
            ->causedBy($user)
            ->performedOn($lock)
            ->withProperties(['ref' => 'PL-'.$lock->id, 'old' => ['locked' => true], 'attributes' => ['locked' => false, 'reason' => $reason]])
            ->event('period-unlocked')
            ->log("Unlocked timesheets for {$range}: {$reason}");
    }
}
