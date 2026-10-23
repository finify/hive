<?php

namespace App\Demo;

use Illuminate\Support\Collection;

/**
 * Validates rows read from the demo JSON at the boundary: a list of JSON
 * objects becomes a collection of string-keyed arrays, and anything that is
 * not an object is dropped.
 */
final class Rows
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function of(mixed $value): Collection
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $rows[] = self::record($row);
            }
        }

        return new Collection($rows);
    }

    /**
     * Rows keyed by one of their fields, for lookups by id.
     *
     * @param  iterable<mixed>  $value
     * @return Collection<int, array<string, mixed>>
     */
    public static function keyed(iterable $value, string $key = 'id'): Collection
    {
        $rows = [];

        foreach ($value as $row) {
            if (is_array($row) && isset($row[$key]) && is_int($row[$key])) {
                $rows[$row[$key]] = self::record($row);
            }
        }

        return new Collection($rows);
    }

    /**
     * JSON object keys are always strings; this makes that explicit.
     *
     * @param  array<mixed>  $row
     * @return array<string, mixed>
     */
    public static function record(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
