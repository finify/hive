<?php

namespace App\Reports;

use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Support\Display;
use App\Support\Money;

/**
 * A report from section 5.11: filterable, scoped to what the viewer may
 * see, shown as a table and exported as CSV from the same rows.
 *
 * Rows hold raw values keyed by column: money as integer pence (GBP),
 * percentages as numbers, dates as Y-m-d. The table formats them; the CSV
 * keeps them plain so they add up in a spreadsheet.
 */
abstract class Report
{
    public function __construct(
        protected readonly Workspace $workspace,
        protected readonly Viewer $viewer,
    ) {}

    abstract public static function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    abstract public function allowed(): bool;

    /**
     * Filters, as name => [label, type (date or select), options for selects].
     *
     * @return array<string, array{label: string, type: string, options?: array<array-key, string>}>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [];
    }

    /**
     * @return list<array{key: string, label: string, type?: string}>
     */
    abstract public function columns(): array;

    /**
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    abstract public function rows(array $filters): array;

    /**
     * Totals for the last row, by column key; columns not given stay blank.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function totals(array $rows): ?array
    {
        return null;
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    public function resolve(array $filters): array
    {
        $resolved = $this->defaults();

        foreach ($this->filters() as $name => $filter) {
            $value = trim((string) ($filters[$name] ?? ''));

            if ($value === '') {
                continue;
            }

            if ($filter['type'] === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                continue;
            }

            if ($filter['type'] === 'select' && ! array_key_exists($value, $filter['options'] ?? [])) {
                continue;
            }

            $resolved[$name] = $value;
        }

        return $resolved;
    }

    public function format(mixed $value, string $type): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $type === 'money' => Money::of((int) $value)->format(false),
            $type === 'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%',
            $type === 'days' => Display::days((float) $value),
            $type === 'date' => Display::date((string) $value),
            $type === 'number' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.'),
            default => (string) $value,
        };
    }

    /**
     * The CSV: a header row, the rows, and the totals. Money in pounds
     * with two decimals.
     *
     * @param  array<string, string>  $filters
     * @return list<list<mixed>>
     */
    public function csv(array $filters): array
    {
        $columns = $this->columns();
        $rows = $this->rows($filters);
        $out = [array_map(fn ($c) => $c['label'].(($c['type'] ?? '') === 'money' ? ' (GBP)' : ''), $columns)];
        $cell = fn (array $row, array $c) => ($c['type'] ?? '') === 'money' && isset($row[$c['key']])
            ? sprintf('%s%d.%02d', $row[$c['key']] < 0 ? '-' : '', intdiv(abs((int) $row[$c['key']]), 100), abs((int) $row[$c['key']]) % 100)
            : ($row[$c['key']] ?? null);

        foreach ($rows as $row) {
            $out[] = array_map(fn ($c) => $cell($row, $c), $columns);
        }

        if ($totals = $this->totals($rows)) {
            $out[] = array_map(fn ($c) => $c === $columns[0] ? 'Total' : $cell($totals, $c), $columns);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function filename(array $filters): string
    {
        return static::key().'-'.implode('-', array_filter($filters)).'.csv';
    }

    protected function today(): string
    {
        return $this->workspace->today()->toDateString();
    }
}
