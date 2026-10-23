<?php

namespace App\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Read-only access to the fictional agency in database/demo/*.json.
 *
 * Nothing here applies permissions or demo changes; use Workspace for that.
 */
class DemoData
{
    /** @var array<string, mixed> */
    private array $loaded = [];

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

            $this->loaded[$file] = json_decode((string) file_get_contents($full), true, 512, JSON_THROW_ON_ERROR);
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
     * The demo's "today". Data is dated around it so the walkthrough stays
     * meaningful whenever it is run.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->raw('organisation')['demo_today']);
    }
}
