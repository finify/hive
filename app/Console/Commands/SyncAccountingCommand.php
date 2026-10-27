<?php

namespace App\Console\Commands;

use App\Actions\Integrations\SyncAccounting;
use Illuminate\Console\Command;

/**
 * Pushes approved supplier bills to the accounting system and pulls back
 * payments. Scheduled hourly; does nothing until Xero is connected.
 */
class SyncAccountingCommand extends Command
{
    protected $signature = 'hive:accounting-sync';

    protected $description = 'Push approved supplier bills to Xero and record the ones paid';

    public function handle(SyncAccounting $sync): int
    {
        $result = $sync->handle();
        $this->info("Pushed {$result['pushed']}, recorded {$result['paid']} paid, {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
