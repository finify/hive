<?php

namespace App\Reports;

use App\Support\DateRange;

class FreelancerSpend extends Report
{
    public static function key(): string
    {
        return 'freelancer-spend';
    }

    public function title(): string
    {
        return 'Freelancer spend';
    }

    public function description(): string
    {
        return 'Approved freelancer time as cost, by person, role or event. Built from day rates, so it needs rate access.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('rates.view');
    }

    public function filters(): array
    {
        return [
            'from' => ['label' => 'Worked from', 'type' => 'date'],
            'to' => ['label' => 'Worked to', 'type' => 'date'],
            'by' => ['label' => 'Group by', 'type' => 'select', 'options' => ['person' => 'Person', 'role' => 'Role', 'event' => 'Event']],
        ];
    }

    public function defaults(): array
    {
        $today = $this->workspace->today();

        return ['from' => $today->subMonths(2)->startOfMonth()->toDateString(), 'to' => $today->toDateString(), 'by' => 'person'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'], ['key' => 'detail', 'label' => 'Across'],
            ['key' => 'days', 'label' => 'Days', 'type' => 'number'], ['key' => 'entries', 'label' => 'Entries', 'type' => 'number'],
            ['key' => 'spend', 'label' => 'Spend', 'type' => 'money'],
        ];
    }

    public function rows(array $filters): array
    {
        $range = DateRange::of($filters['from'], max($filters['from'], $filters['to']));
        $by = $filters['by'] ?? 'person';
        $groups = [];

        foreach ($this->workspace->costEntries()->filter(fn ($c) => $c['source']['type'] === 'timesheet-entry' && $c['kind'] === 'actual') as $cost) {
            $found = $this->workspace->findEntry((int) $cost['source']['id']);

            if ($found === null || ! $range->contains($found[1]['date'])) {
                continue;
            }

            [$sheet, $entry] = $found;
            $event = $this->workspace->event($entry['event_id']);
            $role = $this->role($sheet['person_id'], $entry['event_id'], $entry['date']);
            $person = $this->workspace->personName($sheet['person_id']);
            [$key, $other] = match ($by) {
                'role' => [$role, $person],
                'event' => [$event ? $event['code'].' '.$event['name'] : 'Internal', $person],
                default => [$person, $event['code'] ?? 'Internal'],
            };

            $g = $groups[$key] ?? ['name' => $key, 'across' => [], 'days' => 0.0, 'entries' => 0, 'spend' => 0];
            $g['across'][$other] = true;
            $g['days'] += (float) ($entry['days'] ?: 1);
            $g['entries']++;
            $g['spend'] += (int) $cost['amount_gbp_minor'];
            $groups[$key] = $g;
        }

        $rows = array_map(fn ($g) => [
            'name' => $g['name'], 'detail' => implode(', ', array_keys($g['across'])), 'days' => $g['days'], 'entries' => $g['entries'], 'spend' => $g['spend'],
        ], array_values($groups));
        usort($rows, fn ($a, $b) => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        return $rows === [] ? null : ['days' => array_sum(array_column($rows, 'days')), 'entries' => array_sum(array_column($rows, 'entries')), 'spend' => array_sum(array_column($rows, 'spend'))];
    }

    private function role(int $personId, ?int $eventId, string $date): string
    {
        $assignment = $this->workspace->assignments()->first(fn ($a) => $a['person_id'] === $personId && $a['event_id'] === $eventId
            && DateRange::of($a['start_date'], $a['end_date'])->contains($date));

        return $assignment ? ($this->workspace->crewCalls()->get($assignment['crew_call_id'])['role_name'] ?? 'Crew') : 'Unbooked';
    }
}
