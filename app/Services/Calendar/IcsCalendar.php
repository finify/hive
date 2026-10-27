<?php

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;

/**
 * Writes an iCalendar (RFC 5545) feed: all-day events for bookings, leave
 * and event phases, timed events for travel. Text is escaped and long lines
 * folded at 75 octets, with CRLF line endings.
 */
final class IcsCalendar
{
    /** @var list<string> */
    private array $events = [];

    public function __construct(private readonly string $name) {}

    public function allDay(string $uid, string $start, string $end, string $summary, ?string $description = null, ?string $location = null, bool $tentative = false): self
    {
        // DTEND is exclusive for all-day events.
        $this->events[] = $this->event($uid, [
            'DTSTART;VALUE=DATE:'.str_replace('-', '', $start),
            'DTEND;VALUE=DATE:'.CarbonImmutable::parse($end)->addDay()->format('Ymd'),
        ], $summary, $description, $location, $tentative);

        return $this;
    }

    public function timed(string $uid, CarbonImmutable $start, ?CarbonImmutable $end, string $summary, ?string $description = null, ?string $location = null): self
    {
        $this->events[] = $this->event($uid, [
            'DTSTART:'.$start->utc()->format('Ymd\THis\Z'),
            'DTEND:'.($end ?? $start->addHour())->utc()->format('Ymd\THis\Z'),
        ], $summary, $description, $location, false);

        return $this;
    }

    public function render(): string
    {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lumen & Co Live//Hive//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($this->name), 'X-WR-TIMEZONE:Europe/London', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($this->events as $event) {
            $lines[] = $event;
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), explode("\r\n", implode("\r\n", $lines))))."\r\n";
    }

    /**
     * @param  list<string>  $dates
     */
    private function event(string $uid, array $dates, string $summary, ?string $description, ?string $location, bool $tentative): string
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$uid.'@hive.lumenco',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            ...$dates,
            'SUMMARY:'.self::escape($summary),
            'STATUS:'.($tentative ? 'TENTATIVE' : 'CONFIRMED'),
            'TRANSP:'.($tentative ? 'TRANSPARENT' : 'OPAQUE'),
        ];

        if ($description) {
            $lines[] = 'DESCRIPTION:'.self::escape($description);
        }

        if ($location) {
            $lines[] = 'LOCATION:'.self::escape($location);
        }

        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $text);
    }

    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];

        while (strlen($line) > 75) {
            // Don't split a multibyte character.
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out[] = substr($line, 0, $cut);
            $line = ' '.substr($line, $cut);
        }

        $out[] = $line;

        return implode("\r\n", $out);
    }
}
