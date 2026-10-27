<?php

namespace App\Reports;

use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Models\User;

/**
 * The reports in section 5.11, in the order the Reports page lists them.
 */
final class Reports
{
    /** @var list<class-string<Report>> */
    public const ALL = [
        EventProfitability::class,
        Utilisation::class,
        FreelancerSpend::class,
        SupplierSpend::class,
        PipelineWinRate::class,
        LeaveTakenRemaining::class,
        TimesheetCompliance::class,
        WorkingTimeFlags::class,
    ];

    /**
     * @return list<Report>
     */
    public static function for(User $user): array
    {
        $workspace = app(Workspace::class);
        $viewer = new Viewer($user, $workspace);

        return array_values(array_filter(
            array_map(fn (string $class) => new $class($workspace, $viewer), self::ALL),
            fn (Report $r) => $r->allowed(),
        ));
    }

    public static function find(User $user, string $key): ?Report
    {
        foreach (self::for($user) as $report) {
            if ($report::key() === $key) {
                return $report;
            }
        }

        return null;
    }
}
