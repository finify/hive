<?php

namespace App\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Read-only access to the fictional agency in database/demo/*.json.
 *
 * The files are written around one date (organisation.json demo_today). On
 * the live clock, every date in them is moved forward by whole weeks so that
 * week lands on the current one: weekdays stay right (timesheet weeks start
 * on Monday) and the story stays "this week". Real-world calendars (bank
 * holidays) are never moved.
 *
 * Nothing here applies permissions or demo changes; use Workspace for that.
 */
class DemoData
{
    /** Files whose dates are real-world, or that hold no dates. */
    private const UNSHIFTED = ['bank_holidays', 'personas', 'ask_hive'];

    /** @var array<string, mixed> */
    private array $loaded = [];

    private ?int $shift = null;

    public function __construct(private readonly string $path) {}

    public static function fromConfig(): self
    {
        return new self((string) config('hive.demo.path'));
    }

    /**
     * @return array<mixed>
     */
    public function raw(string $file): array
    {
        if (! array_key_exists($file, $this->loaded)) {
            $full = $this->path.DIRECTORY_SEPARATOR.$file.'.json';

            if (! is_file($full)) {
                throw new RuntimeException("Demo data file [{$file}.json] not found.");
            }

            $data = json_decode((string) file_get_contents($full), true, 512, JSON_THROW_ON_ERROR);
            $this->loaded[$file] = $this->shiftDays() === 0 || in_array($file, self::UNSHIFTED, true) ? $data : $this->move($data);
        }

        return $this->loaded[$file];
    }

    /**
     * Rows of a JSON file, keyed by an integer field (the id by default).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(string $file, string $key = 'id'): Collection
    {
        return Rows::keyed($this->raw($file), $key);
    }

    /**
     * Today's date in the UK, or the data's own date on the fixed demo clock.
     */
    public function today(): CarbonImmutable
    {
        return $this->fixed() ? $this->anchor() : CarbonImmutable::now('Europe/London')->startOfDay();
    }

    /**
     * How many days the demo data is moved forward: whole weeks, so the
     * week of demo_today becomes this week.
     */
    public function shiftDays(): int
    {
        if ($this->shift !== null) {
            return $this->shift;
        }

        if ($this->fixed() || ! config('hive.demo.enabled')) {
            return $this->shift = 0;
        }

        $weeks = (int) round($this->anchor()->startOfWeek()->diffInDays($this->today()->startOfWeek()) / 7);

        return $this->shift = $weeks * 7;
    }

    /**
     * The date the JSON is written around.
     */
    public function anchor(): CarbonImmutable
    {
        $full = $this->path.DIRECTORY_SEPARATOR.'organisation.json';
        $org = json_decode((string) file_get_contents($full), true, 512, JSON_THROW_ON_ERROR);

        return CarbonImmutable::parse((string) $org['demo_today']);
    }

    /**
     * A date written in a seeder, moved like the JSON's dates.
     */
    public function at(string $date): string
    {
        return (string) $this->move($date);
    }

    private function fixed(): bool
    {
        return config('hive.demo.enabled') && config('hive.demo.clock') === 'fixed';
    }

    /**
     * Moves every date and timestamp in the data, and leave years with them.
     */
    private function move(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = $this->move($v, is_string($k) ? $k : null);
            }

            return $out;
        }

        if ($key === 'leave_year' && is_int($value)) {
            return $value + ($this->anchor()->addDays($this->shiftDays())->year - $this->anchor()->year);
        }

        if ($this->shiftDays() === 0 || ! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            return $value;
        }

        $moved = CarbonImmutable::parse($value)->addDays($this->shiftDays());

        return strlen($value) === 10 ? $moved->toDateString() : substr_replace($value, $moved->format('Y-m-d'), 0, 10);
    }
}
