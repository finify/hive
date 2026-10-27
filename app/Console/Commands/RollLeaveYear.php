<?php

namespace App\Console\Commands;

use App\Services\Leave\LeaveBook;
use Illuminate\Console\Command;

/**
 * Opens the new leave year: everyone's allowance carries forward, with up to
 * the carry-over limit of unused days. Scheduled on the first day of the
 * leave year; safe to run again.
 */
class RollLeaveYear extends Command
{
    protected $signature = 'hive:leave-year {year? : The leave year to close (defaults to the one just ended)}';

    protected $description = 'Open the next leave year, carrying over unused annual leave';

    public function handle(LeaveBook $leave): int
    {
        $year = $this->argument('year') !== null ? (int) $this->argument('year') : $leave->yearOf($leave->today()) - 1;
        $created = $leave->rollYear($year);

        activity('people')->withProperties(['ref' => "LY-{$year}", 'attributes' => ['opened' => $year + 1, 'allowances' => $created]])
            ->event('leave-year-opened')->log('Opened the '.($year + 1)." leave year ({$created} allowances)");

        $this->info('Opened '.($year + 1)." for {$created} people.");

        return self::SUCCESS;
    }
}
