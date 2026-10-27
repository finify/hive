<?php

namespace App\Services\Leave;

use App\Demo\DemoData;
use App\Enums\LeaveStatus;
use App\Models\BankHoliday;
use App\Models\LeaveAllowance;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Models\ToilAccrual;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Leave years, bank holidays by region, working days and balances.
 *
 * Annual leave counts against the allowance for the leave year it starts
 * in; TOIL is a running balance of days earned less days taken. Other leave
 * types (sick, compassionate, parental, unpaid, training) don't use either.
 */
class LeaveBook
{
    /** @var array<string, list<string>> */
    private array $holidays = [];

    public function __construct(private readonly DemoData $data) {}

    public function today(): CarbonImmutable
    {
        return $this->data->today();
    }

    public function yearOf(string|CarbonImmutable $date): int
    {
        $date = CarbonImmutable::parse($date);
        [$month, $day] = array_map('intval', explode('-', (string) config('hive.leave.year_start')));

        return $date->lt($date->setDate($date->year, $month, $day)) ? $date->year - 1 : $date->year;
    }

    public function yearRange(int $year): DateRange
    {
        [$month, $day] = array_map('intval', explode('-', (string) config('hive.leave.year_start')));
        $start = CarbonImmutable::create($year, $month, $day);

        return DateRange::of($start->toDateString(), $start->addYear()->subDay()->toDateString());
    }

    /**
     * @return list<string>
     */
    public function bankHolidays(string $region): array
    {
        return $this->holidays[$region] ??= BankHoliday::query()->where('region', $region)->orderBy('date')->get()
            ->map(fn (BankHoliday $b) => $b->date->toDateString())->values()->all();
    }

    public function regionOf(int $personId): string
    {
        return Person::query()->whereKey($personId)->value('bank_holiday_region') ?? 'england-and-wales';
    }

    /**
     * Weekdays in the range that aren't bank holidays where the person works.
     */
    public function workingDays(int $personId, DateRange $range): int
    {
        $holidays = $this->bankHolidays($this->regionOf($personId));

        return count(array_filter($range->dates(), fn (string $d) => CarbonImmutable::parse($d)->isWeekday() && ! in_array($d, $holidays, true)));
    }

    public function isBankHoliday(int $personId, string $date): bool
    {
        return in_array($date, $this->bankHolidays($this->regionOf($personId)), true);
    }

    public function balance(int $personId, ?int $year = null): LeaveBalance
    {
        $year ??= $this->yearOf($this->today());
        $range = $this->yearRange($year);
        $today = $this->today()->toDateString();
        $allowance = LeaveAllowance::query()->where('person_id', $personId)->where('leave_year', $year)->first();

        /** @var Collection<int, LeaveRequest> $requests */
        $requests = LeaveRequest::query()->where('person_id', $personId)
            ->whereIn('status', [LeaveStatus::Approved, LeaveStatus::Pending])
            ->whereIn('leave_type', ['annual', 'toil'])
            ->get();

        $annual = $requests->filter(fn (LeaveRequest $l) => $l->leave_type === 'annual' && $range->contains($l->start_date->toDateString()));
        $sum = fn (Collection $c): float => (float) $c->sum(fn (LeaveRequest $l) => (float) $l->days);
        $approved = $annual->filter(fn (LeaveRequest $l) => $l->status === LeaveStatus::Approved);
        $toil = $requests->filter(fn (LeaveRequest $l) => $l->leave_type === 'toil');

        return new LeaveBalance(
            year: $year,
            allowed: (float) ($allowance->days_allowed ?? config('hive.leave.default_allowance')),
            carriedOver: (float) ($allowance->days_carried_over ?? 0),
            taken: (float) ($allowance->days_taken_before ?? 0) + $sum($approved->filter(fn (LeaveRequest $l) => $l->start_date->toDateString() <= $today)),
            booked: $sum($approved->filter(fn (LeaveRequest $l) => $l->start_date->toDateString() > $today)),
            pending: $sum($annual->filter(fn (LeaveRequest $l) => $l->status === LeaveStatus::Pending)),
            toilEarned: (float) ToilAccrual::query()->where('person_id', $personId)->sum('days'),
            toilUsed: $sum($toil->filter(fn (LeaveRequest $l) => $l->status === LeaveStatus::Approved)),
            toilPending: $sum($toil->filter(fn (LeaveRequest $l) => $l->status === LeaveStatus::Pending)),
        );
    }

    /**
     * Opens next year's allowances: same allowance, and up to the carry-over
     * limit of what is left. Existing rows are left alone, so it is safe to
     * run twice.
     *
     * @return int rows created
     */
    public function rollYear(int $fromYear): int
    {
        $max = (float) config('hive.leave.carry_over_max');
        $created = 0;

        foreach (LeaveAllowance::query()->where('leave_year', $fromYear)->get() as $row) {
            $exists = LeaveAllowance::query()->where('person_id', $row->person_id)->where('leave_year', $fromYear + 1)->exists();

            if ($exists) {
                continue;
            }

            $left = $this->balance($row->person_id, $fromYear)->remaining();

            LeaveAllowance::query()->create([
                'person_id' => $row->person_id,
                'leave_year' => $fromYear + 1,
                'days_allowed' => $row->days_allowed,
                'days_carried_over' => max(0, min($max, $left)),
                'days_taken_before' => 0,
            ]);
            $created++;
        }

        return $created;
    }
}
