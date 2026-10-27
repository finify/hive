<?php

namespace App\Actions\Leave;

use App\Demo\Workspace;
use App\Enums\EmploymentType;
use App\Enums\EntryStatus;
use App\Models\Person;
use App\Models\TimesheetEntry;
use App\Models\ToilAccrual;
use App\Models\User;
use App\Services\Leave\LeaveBook;
use App\Support\Display;
use Carbon\CarbonImmutable;

/**
 * Earns time off in lieu from an approved timesheet entry on an event, when
 * the day is one config('hive.leave.toil.accrue_on') names (weekends and
 * bank holidays). Employees and fixed-term staff only: freelancers are paid
 * for the day. Up to one day per day worked; once per entry.
 */
class AccrueToil
{
    public function __construct(
        private readonly LeaveBook $leave,
        private readonly Workspace $workspace,
    ) {}

    /**
     * @param  bool  $quiet  true when seeding: no log entry, no notice
     */
    public function forEntry(int $entryId, ?User $approver = null, bool $quiet = false): ?ToilAccrual
    {
        $entry = TimesheetEntry::query()->with('timesheet')->find($entryId);

        if ($entry === null || $entry->status !== EntryStatus::Approved || $entry->event_id === null) {
            return null;
        }

        $person = Person::query()->find($entry->timesheet->person_id);

        if ($person === null || ! in_array($person->employment_type, [EmploymentType::Employee, EmploymentType::FixedTerm], true)) {
            return null;
        }

        $date = $entry->date->toDateString();
        $why = $this->qualifies($person->id, $date);

        if ($why === null || ToilAccrual::query()->where('timesheet_entry_id', $entryId)->exists()) {
            return null;
        }

        $days = $this->days((float) $entry->days, (float) $entry->hours);

        if ($days <= 0) {
            return null;
        }

        $event = $this->workspace->event($entry->event_id);
        $accrual = ToilAccrual::query()->create([
            'person_id' => $person->id,
            'date' => $date,
            'days' => $days,
            'reason' => ($event['code'] ?? 'Event').' on a '.$why,
            'timesheet_entry_id' => $entryId,
            'event_id' => $entry->event_id,
            'recorded_by' => $approver?->person_id,
        ]);

        if ($quiet) {
            return $accrual;
        }

        activity('people')
            ->causedBy($approver)
            ->performedOn($accrual)
            ->withProperties(['ref' => 'TE-'.$entryId, 'attributes' => ['toil_days' => $days, 'date' => $date]])
            ->event('toil-earned')
            ->log(sprintf('%s earned %s TOIL for %s', $this->workspace->personName($person->id), Display::days($days), Display::date($date)));

        $this->workspace->notify($person->id, 'leave', 'Time off in lieu earned', Display::days($days).' for working '.Display::date($date).'.', 'leave');

        return $accrual;
    }

    private function qualifies(int $personId, string $date): ?string
    {
        $on = (array) config('hive.leave.toil.accrue_on');

        if (in_array('bank_holiday', $on, true) && $this->leave->isBankHoliday($personId, $date)) {
            return 'bank holiday';
        }

        if (in_array('weekend', $on, true) && CarbonImmutable::parse($date)->isWeekend()) {
            return 'weekend';
        }

        return null;
    }

    /**
     * Days as entered, or hours over a standard day, to the nearest half day,
     * at most one.
     */
    private function days(float $days, float $hours): float
    {
        $raw = $days > 0 ? $days : $hours / (float) config('hive.leave.toil.hours_per_day');

        return min(1.0, round($raw * 2) / 2);
    }
}
