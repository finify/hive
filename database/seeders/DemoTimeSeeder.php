<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Demo\Rows;
use App\Enums\EntryStatus;
use App\Enums\LeaveStatus;
use App\Enums\TimesheetStatus;
use App\Models\Approval;
use App\Models\CostEntry;
use App\Models\LeaveRequest;
use App\Models\PeriodLock;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Services\Approvals\ApprovalEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads timesheets, cost entries and the payroll locks, then opens approvals
 * for everything still waiting (submitted time and pending leave). Runs
 * after the demo users, because HR approves leave.
 */
class DemoTimeSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        $data = app(DemoData::class);
        $engine = app(ApprovalEngine::class);

        DB::transaction(function () use ($data, $engine) {
            foreach (Rows::of($data->raw('timesheets')) as $row) {
                $sheet = Timesheet::query()->forceCreate([
                    'id' => $row['id'], 'person_id' => $row['person_id'], 'week_start' => $row['week_start'], 'status' => $row['status'],
                    'submitted_at' => $row['submitted_at'], 'locked_at' => $row['locked_at'],
                ]);

                foreach (Rows::of($row['entries']) as $entry) {
                    TimesheetEntry::query()->forceCreate(['timesheet_id' => $sheet->id] + $entry);
                }
            }

            // Payroll has run for July to September; each month was locked on the 5th.
            $lockedUntil = $data->raw('organisation')['timesheets_locked_until'] ?? null;

            if ($lockedUntil) {
                for ($month = CarbonImmutable::parse($lockedUntil)->subMonths(2)->startOfMonth(); $month->lessThanOrEqualTo(CarbonImmutable::parse($lockedUntil)); $month = $month->addMonth()) {
                    PeriodLock::query()->create([
                        'period_start' => $month->toDateString(), 'period_end' => $month->endOfMonth()->toDateString(),
                        'locked_by' => 20, 'locked_at' => $month->addMonth()->day(5)->setTime(8, 0), 'reason' => 'Payroll run',
                    ]);
                }
            }

            foreach (Rows::of($data->raw('cost_entries')) as $row) {
                $source = is_array($row['source']) ? $row['source'] : [];
                CostEntry::query()->forceCreate([
                    'id' => $row['id'], 'event_id' => $row['event_id'], 'budget_line_id' => $row['budget_line_id'],
                    'source_type' => $source['type'] ?? 'manual', 'source_id' => $source['id'] ?? null, 'source_ref' => $source['ref'] ?? null,
                    'kind' => $row['kind'], 'amount_minor' => $row['amount_minor'], 'currency' => $row['currency'], 'fx_rate' => $row['fx_rate'],
                    'amount_gbp_minor' => $row['amount_gbp_minor'], 'posted_at' => $row['posted_at'],
                ]);
            }

            // Open approvals for what is still waiting, dated when it was submitted.
            $waiting = TimesheetEntry::query()->with('timesheet')->where('status', EntryStatus::Pending)->get()
                ->filter(fn (TimesheetEntry $e) => ! in_array($e->timesheet->status, [TimesheetStatus::Draft, TimesheetStatus::Locked], true));

            foreach ($waiting as $entry) {
                $this->backdate($engine->request($entry), $entry->timesheet->submitted_at?->toDateTimeString());
            }

            foreach (LeaveRequest::query()->where('status', LeaveStatus::Pending)->get() as $leave) {
                $this->backdate($engine->request($leave), $leave->requested_at?->toDateTimeString());
            }
        });
    }

    /**
     * @param  Collection<int, Approval>  $rows
     */
    private function backdate(Collection $rows, ?string $at): void
    {
        if ($at !== null) {
            Approval::query()->whereIn('id', $rows->pluck('id'))->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }
}
