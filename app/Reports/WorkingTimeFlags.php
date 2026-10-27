<?php

namespace App\Reports;

use App\Enums\EntryStatus;

class WorkingTimeFlags extends Report
{
    public static function key(): string
    {
        return 'working-time-flags';
    }

    public function title(): string
    {
        return 'Working time flags';
    }

    public function description(): string
    {
        return 'Timesheet entries that broke a working time rule: long shifts, missing breaks, short rest, time with no booking or above the crew call.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('timesheets.view_all');
    }

    public function filters(): array
    {
        return [
            'from' => ['label' => 'From', 'type' => 'date'],
            'to' => ['label' => 'To', 'type' => 'date'],
        ];
    }

    public function defaults(): array
    {
        $today = $this->workspace->today();

        return ['from' => $today->subWeeks(8)->startOfWeek()->toDateString(), 'to' => $today->toDateString()];
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'name', 'label' => 'Person'], ['key' => 'event', 'label' => 'Event'],
            ['key' => 'hours', 'label' => 'Hours', 'type' => 'number'], ['key' => 'flag', 'label' => 'Flag'], ['key' => 'status', 'label' => 'Entry'],
        ];
    }

    public function rows(array $filters): array
    {
        $rows = [];

        foreach ($this->workspace->timesheets() as $sheet) {
            $flags = $this->workspace->entryFlags($sheet);

            foreach ($sheet['entries'] as $entry) {
                if ($entry['date'] < $filters['from'] || $entry['date'] > $filters['to']) {
                    continue;
                }

                foreach ($flags[$entry['id']] ?? [] as $flag) {
                    $rows[] = [
                        'date' => $entry['date'], 'name' => $this->workspace->personName($sheet['person_id']),
                        'event' => $entry['event_id'] ? ($this->workspace->event($entry['event_id'])['code'] ?? '') : $entry['internal_code'],
                        'hours' => $entry['hours'], 'flag' => $flag['message'], 'status' => EntryStatus::from($entry['status'])->label(),
                    ];
                }
            }
        }

        usort($rows, fn ($a, $b) => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);

        return $rows;
    }
}
