<?php

namespace App\Reports;

use App\Models\LeaveRequest;
use App\Models\Person;
use App\Services\Leave\LeaveBook;

class LeaveTakenRemaining extends Report
{
    public static function key(): string
    {
        return 'leave';
    }

    public function title(): string
    {
        return 'Leave taken and remaining';
    }

    public function description(): string
    {
        return 'Annual leave and TOIL for each employee in a leave year. Sickness days are shown to HR only.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('leave.view_all');
    }

    public function filters(): array
    {
        $book = app(LeaveBook::class);
        $current = $book->yearOf($book->today());
        $years = [];

        foreach ([$current - 1, $current, $current + 1] as $y) {
            $years[(string) $y] = (string) $y;
        }

        return ['year' => ['label' => 'Leave year', 'type' => 'select', 'options' => $years]];
    }

    public function defaults(): array
    {
        $book = app(LeaveBook::class);

        return ['year' => (string) $book->yearOf($book->today())];
    }

    public function columns(): array
    {
        $columns = [
            ['key' => 'name', 'label' => 'Name'], ['key' => 'department', 'label' => 'Department'],
            ['key' => 'entitlement', 'label' => 'Entitlement', 'type' => 'days'], ['key' => 'taken', 'label' => 'Taken', 'type' => 'days'],
            ['key' => 'booked', 'label' => 'Booked', 'type' => 'days'], ['key' => 'pending', 'label' => 'Waiting', 'type' => 'days'],
            ['key' => 'remaining', 'label' => 'Remaining', 'type' => 'days'], ['key' => 'toil', 'label' => 'TOIL left', 'type' => 'days'],
        ];

        if ($this->viewer->can('leave.manage')) {
            $columns[] = ['key' => 'sick', 'label' => 'Sickness', 'type' => 'days'];
        }

        return $columns;
    }

    public function rows(array $filters): array
    {
        $book = app(LeaveBook::class);
        $year = (int) $filters['year'];
        $range = $book->yearRange($year);
        $sick = $this->viewer->can('leave.manage');
        $rows = [];

        foreach (Person::query()->where('is_active', true)->whereIn('employment_type', ['employee', 'fixed-term'])->orderBy('first_name')->get() as $p) {
            $b = $book->balance($p->id, $year);
            $row = [
                'name' => $p->first_name.' '.$p->last_name, 'department' => $this->workspace->departmentName($p->department_id),
                'entitlement' => $b->entitlement(), 'taken' => $b->taken, 'booked' => $b->booked, 'pending' => $b->pending,
                'remaining' => $b->remaining(), 'toil' => $b->toilRemaining(),
            ];

            if ($sick) {
                $row['sick'] = (float) LeaveRequest::query()->where('person_id', $p->id)->where('leave_type', 'sick')->where('status', 'approved')
                    ->whereDate('start_date', '>=', $range->start->toDateString())->whereDate('start_date', '<=', $range->end->toDateString())->sum('days');
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = [];

        foreach (['entitlement', 'taken', 'booked', 'pending', 'remaining', 'toil', 'sick'] as $k) {
            if (array_key_exists($k, $rows[0])) {
                $totals[$k] = array_sum(array_column($rows, $k));
            }
        }

        return $totals;
    }
}
