<?php

namespace App\Support\Resourcing;

use App\Support\DateRange;
use App\Support\Time\WorkingTimeRules;
use Carbon\CarbonImmutable;

/**
 * Working time warnings for a person's booking pattern, before any time is
 * worked: each booked day is a shift from the crew call's call time to its
 * wrap time. Flags less than 11 hours' rest between shifts, shifts over the
 * long-shift limit, and an average week above the weekly limit over the
 * reference period (17 weeks by default), counting the booked weeks only.
 * Warnings for the producer, not legal advice; thresholds are in
 * config/hive.php. Nobody in the demo has opted out of the 48-hour limit.
 */
final class ShiftPattern
{
    public function __construct(
        private readonly WorkingTimeRules $rules,
        private readonly float $weeklyHours = 48,
        private readonly int $scheduledBreakMinutes = 20,
        private readonly int $referenceWeeks = 17,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            WorkingTimeRules::fromConfig(),
            (float) config('hive.working_time.weekly_average_hours', 48),
            (int) config('hive.working_time.break_minutes', 20),
            (int) config('hive.working_time.reference_weeks', 17),
        );
    }

    /**
     * @param  list<array{id: int|string, dates: DateRange, call_time: string, wrap_time: string}>  $bookings
     * @return array<int|string, list<string>> warnings keyed by booking id; bookings without warnings are left out
     */
    public function warnings(array $bookings): array
    {
        $shifts = [];
        $owner = [];
        $weeks = [];

        foreach ($bookings as $booking) {
            foreach ($booking['dates']->dates() as $date) {
                $id = $booking['id'].'|'.$date;
                $owner[$id] = $booking['id'];
                $shifts[] = ['id' => $id, 'date' => $date, 'start_time' => $booking['call_time'], 'end_time' => $booking['wrap_time'], 'break_minutes' => $this->scheduledBreakMinutes];

                $week = CarbonImmutable::parse($date)->startOfWeek()->toDateString();
                $weeks[$week]['hours'] = ($weeks[$week]['hours'] ?? 0)
                    + WorkingTimeRules::workedMinutes($booking['call_time'], $booking['wrap_time'], $this->scheduledBreakMinutes) / 60;
                $weeks[$week]['bookings'][$booking['id']] = true;
            }
        }

        // The same flag on several days of one booking reads once, with the days.
        $byMessage = [];

        foreach ($this->rules->flag($shifts) as $shiftId => $flags) {
            [, $date] = explode('|', (string) $shiftId);

            foreach ($flags as $flag) {
                $byMessage[$owner[$shiftId]][$flag['message']][] = $date;
            }
        }

        $warnings = [];

        foreach ($byMessage as $id => $messages) {
            foreach ($messages as $message => $dates) {
                $warnings[$id][] = (count($dates) === 1
                    ? CarbonImmutable::parse($dates[0])->format('j M')
                    : count($dates).' days, '.DateRange::of(min($dates), max($dates))->format()).': '.$message;
            }
        }

        // The average over the reference period ending with each booked week.
        foreach ($weeks as $week => $row) {
            $end = CarbonImmutable::parse($week);
            $total = 0.0;

            for ($i = 0; $i < $this->referenceWeeks; $i++) {
                $total += $weeks[$end->subWeeks($i)->toDateString()]['hours'] ?? 0;
            }

            $average = $total / $this->referenceWeeks;

            if ($average > $this->weeklyHours) {
                foreach (array_keys($row['bookings']) as $id) {
                    $warnings[$id][] = sprintf('Averaging %s hours a week over the %d weeks to %s, above the %s-hour limit', round($average, 1), $this->referenceWeeks, $end->addDays(6)->format('j M'), $this->weeklyHours);
                }
            }
        }

        return array_map(fn (array $list) => array_values(array_unique($list)), $warnings);
    }
}
