<?php

namespace App\Reports;

use App\Support\DateRange;
use Carbon\CarbonImmutable;

class Utilisation extends Report
{
    public static function key(): string
    {
        return 'utilisation';
    }

    public function title(): string
    {
        return 'Utilisation';
    }

    public function description(): string
    {
        return 'Weekdays booked on events as a share of weekdays in the period, by person or by department.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('rota.view_all');
    }

    public function filters(): array
    {
        $departments = ['all' => 'All departments'];

        foreach ($this->workspace->departments() as $d) {
            $departments[(string) $d['id']] = $d['name'];
        }

        return [
            'from' => ['label' => 'From', 'type' => 'date'],
            'to' => ['label' => 'To', 'type' => 'date'],
            'department' => ['label' => 'Department', 'type' => 'select', 'options' => $departments],
            'by' => ['label' => 'Show', 'type' => 'select', 'options' => ['person' => 'By person', 'department' => 'By department']],
        ];
    }

    public function defaults(): array
    {
        $today = $this->workspace->today();

        return ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'department' => 'all', 'by' => 'person'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'], ['key' => 'department', 'label' => 'Department'], ['key' => 'type', 'label' => 'Type'],
            ['key' => 'weekdays', 'label' => 'Weekdays', 'type' => 'number'], ['key' => 'booked', 'label' => 'Days booked', 'type' => 'number'],
            ['key' => 'utilisation', 'label' => 'Utilisation', 'type' => 'percent'],
        ];
    }

    public function rows(array $filters): array
    {
        $range = DateRange::of($filters['from'], max($filters['from'], $filters['to']));
        $weekdays = count(array_filter($range->dates(), fn ($d) => CarbonImmutable::parse($d)->isWeekday()));
        $rows = [];

        foreach ($this->viewer->people()->filter(fn ($p) => $p['is_active'])->sortBy('first_name') as $p) {
            if (($filters['department'] ?? 'all') !== 'all' && (string) $p['department_id'] !== $filters['department']) {
                continue;
            }

            $share = $this->workspace->utilisation($p['id'], $range);
            $rows[] = [
                'name' => $p['first_name'].' '.$p['last_name'], 'department' => $this->workspace->departmentName($p['department_id']),
                'type' => ucfirst(str_replace('-', ' ', $p['employment_type'])), 'weekdays' => $weekdays,
                'booked' => (int) round($share * $weekdays), 'utilisation' => round($share * 100, 1),
            ];
        }

        if (($filters['by'] ?? 'person') === 'department') {
            $grouped = [];

            foreach ($rows as $r) {
                $g = $grouped[$r['department']] ?? ['name' => $r['department'], 'department' => $r['department'], 'type' => '', 'weekdays' => 0, 'booked' => 0];
                $g['weekdays'] += $r['weekdays'];
                $g['booked'] += $r['booked'];
                $g['people'] = ($g['people'] ?? 0) + 1;
                $grouped[$r['department']] = $g;
            }

            $rows = array_values(array_map(fn ($g) => $g + ['utilisation' => $g['weekdays'] ? round($g['booked'] * 100 / $g['weekdays'], 1) : 0], $grouped));
            $rows = array_map(fn ($g) => ['type' => $g['people'].' '.($g['people'] === 1 ? 'person' : 'people')] + $g, $rows);
            usort($rows, fn ($a, $b) => $b['utilisation'] <=> $a['utilisation']);
        }

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        $weekdays = array_sum(array_column($rows, 'weekdays'));

        return $rows === [] ? null : ['booked' => array_sum(array_column($rows, 'booked')), 'weekdays' => $weekdays, 'utilisation' => $weekdays ? round(array_sum(array_column($rows, 'booked')) * 100 / $weekdays, 1) : 0];
    }
}
