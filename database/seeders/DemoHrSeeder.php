<?php

namespace Database\Seeders;

use App\Actions\Leave\AccrueToil;
use App\Demo\DemoData;
use App\Demo\Rows;
use App\Enums\EntryStatus;
use App\Models\BankHoliday;
use App\Models\LeaveAllowance;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Models\TimesheetEntry;
use App\Models\ToilAccrual;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bank holidays, leave allowances and TOIL (after time, so TOIL can be
 * earned from the seeded approved weekend work), then onboarding and
 * offboarding templates and checklists.
 */
class DemoHrSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        $data = app(DemoData::class);

        DB::transaction(function () use ($data) {
            foreach (Rows::of($data->raw('bank_holidays')) as $row) {
                BankHoliday::query()->create($row);
            }

            Person::query()->where('location_city', 'Glasgow')->update(['bank_holiday_region' => 'scotland']);
            Person::query()->where('location_city', 'Belfast')->update(['bank_holiday_region' => 'northern-ireland']);

            // The JSON's days_taken is the year's total; what Hive already
            // records as approved leave is taken off to give the opening figure.
            foreach (Rows::of($data->raw('leave_allowances')) as $row) {
                $recorded = (float) LeaveRequest::query()->where('person_id', $row['person_id'])->where('leave_type', 'annual')
                    ->where('status', 'approved')->whereYear('start_date', $row['leave_year'])->sum('days');

                LeaveAllowance::query()->create([
                    'person_id' => $row['person_id'], 'leave_year' => $row['leave_year'],
                    'days_allowed' => $row['days_allowed'], 'days_carried_over' => $row['days_carried_over'],
                    'days_taken_before' => max(0, $row['days_taken'] - $recorded),
                ]);
            }

            foreach (Rows::of($data->raw('toil_accruals')) as $row) {
                ToilAccrual::query()->create($row);
            }

            $toil = app(AccrueToil::class);

            foreach (TimesheetEntry::query()->where('status', EntryStatus::Approved)->whereNotNull('event_id')->pluck('id') as $id) {
                $toil->forEntry($id, quiet: true);
            }
        });

        $this->call(DemoChecklistSeeder::class);
    }
}
