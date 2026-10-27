<?php

namespace App\Reports;

use App\Models\Approval;
use App\Models\TimesheetEntry;
use Carbon\CarbonImmutable;

/**
 * A week is on time when submitted by noon on the Monday after it; an
 * entry is approved on time within remind_after_days of being submitted.
 */
class TimesheetCompliance extends Report
{
    public static function key(): string
    {
        return 'timesheet-compliance';
    }

    public function title(): string
    {
        return 'Timesheet compliance';
    }

    public function description(): string
    {
        return 'Weeks submitted, submitted on time (by noon the following Monday) and approved on time, per person.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('timesheets.view_all');
    }

    public function filters(): array
    {
        return [
            'from' => ['label' => 'Weeks from', 'type' => 'date'],
            'to' => ['label' => 'Weeks to', 'type' => 'date'],
        ];
    }

    public function defaults(): array
    {
        $today = $this->workspace->today();

        return ['from' => $today->subWeeks(8)->startOfWeek()->toDateString(), 'to' => $today->startOfWeek()->toDateString()];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'], ['key' => 'weeks', 'label' => 'Weeks', 'type' => 'number'],
            ['key' => 'submitted', 'label' => 'Submitted', 'type' => 'number'], ['key' => 'on_time', 'label' => 'On time', 'type' => 'number'],
            ['key' => 'on_time_percent', 'label' => 'On time %', 'type' => 'percent'],
            ['key' => 'entries_decided', 'label' => 'Entries approved', 'type' => 'number'],
            ['key' => 'approved_on_time_percent', 'label' => 'Approved on time %', 'type' => 'percent'],
        ];
    }

    public function rows(array $filters): array
    {
        $limit = (int) config('hive.approvals.remind_after_days');
        $approvals = Approval::query()->where('approvable_type', TimesheetEntry::class)->get(['approvable_id', 'created_at'])->keyBy('approvable_id');
        $people = [];

        foreach ($this->workspace->timesheets() as $sheet) {
            if ($sheet['week_start'] < $filters['from'] || $sheet['week_start'] > $filters['to'] || $sheet['status'] === 'draft' && $sheet['week_start'] >= $this->workspace->today()->startOfWeek()->toDateString()) {
                continue;
            }

            $deadline = CarbonImmutable::parse($sheet['week_start'], 'Europe/London')->addWeek()->setTime(12, 0);
            $p = $people[$sheet['person_id']] ?? ['weeks' => 0, 'submitted' => 0, 'on_time' => 0, 'decided' => 0, 'decided_on_time' => 0];
            $p['weeks']++;

            if ($sheet['submitted_at']) {
                $p['submitted']++;
                $p['on_time'] += CarbonImmutable::parse($sheet['submitted_at'])->lte($deadline) ? 1 : 0;
            }

            foreach ($sheet['entries'] as $entry) {
                if ($entry['status'] !== 'approved' || ! $entry['approved_at']) {
                    continue;
                }

                $approval = $approvals->get($entry['id']);
                $asked = $approval !== null ? $approval->created_at : ($sheet['submitted_at'] ? CarbonImmutable::parse($sheet['submitted_at']) : null);
                $p['decided']++;
                $p['decided_on_time'] += $asked && CarbonImmutable::parse($entry['approved_at'])->lte(CarbonImmutable::parse($asked)->addDays($limit)) ? 1 : 0;
            }

            $people[$sheet['person_id']] = $p;
        }

        $rows = [];

        foreach ($people as $id => $p) {
            $rows[] = [
                'name' => $this->workspace->personName($id), 'weeks' => $p['weeks'], 'submitted' => $p['submitted'], 'on_time' => $p['on_time'],
                'on_time_percent' => round($p['on_time'] * 100 / $p['weeks'], 1),
                'entries_decided' => $p['decided'],
                'approved_on_time_percent' => $p['decided'] ? round($p['decided_on_time'] * 100 / $p['decided'], 1) : null,
            ];
        }

        usort($rows, fn ($a, $b) => [$a['on_time_percent'] ?? 0, $a['name']] <=> [$b['on_time_percent'] ?? 0, $b['name']]);

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        $weeks = array_sum(array_column($rows, 'weeks'));

        return $rows === [] ? null : [
            'weeks' => $weeks, 'submitted' => array_sum(array_column($rows, 'submitted')), 'on_time' => array_sum(array_column($rows, 'on_time')),
            'on_time_percent' => $weeks ? round(array_sum(array_column($rows, 'on_time')) * 100 / $weeks, 1) : null,
            'entries_decided' => array_sum(array_column($rows, 'entries_decided')),
        ];
    }
}
