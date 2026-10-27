<?php

namespace Database\Seeders;

use App\Actions\Finance\PostCost;
use App\Demo\DemoData;
use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\TravelKind;
use App\Models\TravelBooking;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Travel and hotels for the Lisbon congress, with their costs posted as
 * actuals the way SaveTravelBooking does (they draw down the remaining
 * estimate on the Travel and Accommodation lines).
 */
class DemoTravelSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        $workspace = app(Workspace::class);
        $costs = app(PostCost::class);

        DB::transaction(function () use ($workspace, $costs) {
            foreach (Rows::of(app(DemoData::class)->raw('travel_bookings')) as $row) {
                $booking = TravelBooking::query()->create($row + ['status' => 'booked', 'booked_by' => $workspace->event($row['event_id'])['producer_id'] ?? null]);
                $lines = Rows::of($workspace->budget($booking->event_id)['lines'] ?? []);
                $line = $lines->firstWhere('category', TravelKind::from($row['kind'])->costCategory()) ?? $lines->firstWhere('category', 'contingency');
                $costs->actual($booking->event_id, $line['id'] ?? null, 'travel', $booking->id, $booking->ref(), Money::of($booking->cost_minor, $booking->currency), null, true);
            }
        });
    }
}
