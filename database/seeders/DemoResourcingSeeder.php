<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Demo\Rows;
use App\Models\Assignment;
use App\Models\CrewCall;
use App\Models\LeaveRequest;
use App\Models\Unavailability;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads crew calls, assignments, unavailability and leave requests from
 * database/demo, keeping the JSON ids that timesheets (still JSON) and the
 * seeded activity refer to.
 */
class DemoResourcingSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        $data = app(DemoData::class);

        DB::transaction(function () use ($data) {
            foreach (Rows::of($data->raw('crew_calls')) as $row) {
                CrewCall::query()->forceCreate($row);
            }

            foreach (Rows::of($data->raw('assignments')) as $row) {
                Assignment::query()->forceCreate($row);
            }

            foreach (Rows::of($data->raw('unavailability')) as $row) {
                Unavailability::query()->forceCreate($row);
            }

            foreach (Rows::of($data->raw('leave_requests')) as $row) {
                LeaveRequest::query()->forceCreate([
                    'id' => $row['id'], 'person_id' => $row['person_id'], 'leave_type' => $row['leave_type'],
                    'start_date' => $row['start_date'], 'end_date' => $row['end_date'], 'days' => $row['days'],
                    'reason' => $row['reason'], 'status' => $row['status'], 'approved_by' => $row['approved_by'],
                    'approved_at' => $row['approved_by'] ? ($row['requested_at'] ?? $row['start_date']) : null,
                    'requested_at' => $row['requested_at'] ?? null,
                ]);
            }
        });
    }
}
