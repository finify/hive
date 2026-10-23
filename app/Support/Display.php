<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * UK display formats: 23 Oct 2026, 24-hour time, Europe/London.
 */
final class Display
{
    public static function date(DateTimeInterface|string|null $date, bool $weekday = false): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        $day = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);

        return $day->format($weekday ? 'D j M Y' : 'j M Y');
    }

    public static function shortDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        return ($date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date))->format('j M');
    }

    /**
     * A stored UTC timestamp shown in the display timezone.
     */
    public static function dateTime(DateTimeInterface|string|null $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '—';
        }

        $utc = $timestamp instanceof DateTimeInterface
            ? CarbonImmutable::instance($timestamp)
            : CarbonImmutable::parse($timestamp, 'UTC');

        return $utc->setTimezone(config('hive.display_timezone'))->format('j M Y, H:i');
    }

    public static function hours(float|int $hours): string
    {
        $text = rtrim(rtrim(number_format((float) $hours, 2), '0'), '.');

        return $text.' h';
    }

    public static function days(float|int $days): string
    {
        $text = rtrim(rtrim(number_format((float) $days, 1), '0'), '.');

        return $text.' '.((float) $days === 1.0 ? 'day' : 'days');
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * A stable, pale identity hue for a person (0–359), spread by the golden angle.
     */
    public static function hue(int $id): int
    {
        return (int) round(fmod($id * 137.508, 360));
    }
}
