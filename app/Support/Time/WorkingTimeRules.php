<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;

/**
 * Flags for timesheet entries and shift patterns, using UK Working Time
 * Regulations reference values. These are warnings for the approver, not
 * legal advice; thresholds live in config/hive.php.
 */
final class WorkingTimeRules
{
    public const LONG_SHIFT = 'long-shift';

    public const SHORT_REST = 'short-rest';

    public const NO_BREAK = 'no-break';

    public const NO_ASSIGNMENT = 'no-assignment';

    public const ABOVE_CALL = 'above-call';

    public function __construct(
        private readonly float $longShiftHours = 12,
        private readonly float $minRestHours = 11,
        private readonly float $breakAfterHours = 6,
        private readonly int $breakMinutes = 20,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (float) config('hive.working_time.long_shift_hours'),
            (float) config('hive.working_time.min_rest_hours'),
            (float) config('hive.working_time.break_after_hours'),
            (int) config('hive.working_time.break_minutes'),
        );
    }

    /**
     * Worked minutes for a shift. An end time before the start runs past midnight.
     */
    public static function workedMinutes(string $start, string $end, int $breakMinutes): int
    {
        $span = self::minutesOfDay($end) - self::minutesOfDay($start);

        if ($span <= 0) {
            $span += 24 * 60;
        }

        return max(0, $span - $breakMinutes);
    }

    /**
     * Flags for a set of shifts by one person, keyed by the shift's id.
     *
     * @param  list<array{id: int|string, date: string, start_time: string, end_time: string, break_minutes: int, assigned?: bool, call_hours?: float|null}>  $shifts
     * @return array<int|string, list<array{code: string, message: string}>>
     */
    public function flag(array $shifts): array
    {
        usort($shifts, fn ($a, $b) => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]);
        $flags = [];
        $previousEnd = null;

        foreach ($shifts as $shift) {
            $id = $shift['id'];
            $flags[$id] = [];
            $worked = self::workedMinutes($shift['start_time'], $shift['end_time'], $shift['break_minutes']);
            $hours = $worked / 60;

            if ($hours > $this->longShiftHours) {
                $flags[$id][] = ['code' => self::LONG_SHIFT, 'message' => sprintf('%s-hour shift, over the %s-hour limit', self::hours($hours), self::hours($this->longShiftHours))];
            }

            if ($hours > $this->breakAfterHours && $shift['break_minutes'] < $this->breakMinutes) {
                $flags[$id][] = ['code' => self::NO_BREAK, 'message' => sprintf('No %d-minute break recorded on a shift over %s hours', $this->breakMinutes, self::hours($this->breakAfterHours))];
            }

            $start = CarbonImmutable::parse($shift['date'].' '.$shift['start_time']);

            if ($previousEnd !== null) {
                $rest = $previousEnd->diffInMinutes($start, false) / 60;

                if ($rest >= 0 && $rest < $this->minRestHours) {
                    $flags[$id][] = ['code' => self::SHORT_REST, 'message' => sprintf('Only %s hours’ rest since the previous shift', self::hours($rest))];
                }
            }

            if (($shift['assigned'] ?? true) === false) {
                $flags[$id][] = ['code' => self::NO_ASSIGNMENT, 'message' => 'No booking on this date'];
            }

            $callHours = $shift['call_hours'] ?? null;

            if ($callHours !== null && $hours > $callHours + 2) {
                $flags[$id][] = ['code' => self::ABOVE_CALL, 'message' => sprintf('%s hours worked; the crew call was %s hours', self::hours($hours), self::hours($callHours))];
            }

            $end = CarbonImmutable::parse($shift['date'].' '.$shift['end_time']);
            $previousEnd = $end->lessThanOrEqualTo($start) ? $end->addDay() : $end;
        }

        return $flags;
    }

    private static function minutesOfDay(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private static function hours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 1), '0'), '.');
    }
}
