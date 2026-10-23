<?php

namespace App\Console\Commands;

use App\Enums\DocumentType;
use App\Models\Approval;
use App\Models\ApprovalDelegation;
use App\Models\Document;
use App\Models\Event;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FreelancerInvoice;
use App\Models\LeaveRequest;
use App\Models\PeriodLock;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Timesheet;
use App\Models\Unavailability;
use App\Notifications\CallSheetIssued;
use App\Notifications\HiveNotice;
use Database\Seeders\DemoEventsSeeder;
use Database\Seeders\DemoFinanceSeeder;
use Database\Seeders\DemoResourcingSeeder;
use Database\Seeders\DemoTimeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Events, resourcing, time and finance live in the database, so changes
 * made on the demo are shared by every visitor until this puts the seeded
 * data back. Scheduled hourly while demo mode is on; never runs in
 * production.
 */
class RefreshDemo extends Command
{
    protected $signature = 'hive:demo-refresh';

    protected $description = 'Put the demo events, resourcing, time, finance and notices back to the seeded state';

    public function handle(): int
    {
        if (app()->isProduction() || ! config('hive.demo.enabled')) {
            $this->error('The demo can only be refreshed with demo mode on, outside production.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('filesystems.documents_disk', 'local'));
        $files = Document::query()->where('documentable_type', Event::class)->whereNotNull('file_path')->pluck('file_path');

        DB::transaction(function () {
            Document::query()->where('documentable_type', Event::class)->delete();
            Task::query()->delete();
            TaskTemplate::query()->delete();
            // Crew calls and assignments go with their events.
            Event::withTrashed()->forceDelete();
            Approval::query()->delete();
            ApprovalDelegation::query()->delete();
            PeriodLock::query()->delete();
            // Budgets, POs, invoices, billing and cost entries went with their events.
            Timesheet::query()->delete();
            Expense::query()->delete();
            FreelancerInvoice::query()->delete();
            ExchangeRate::query()->delete();
            Document::query()->where('type', DocumentType::SelfBillingAgreement)->delete();
            LeaveRequest::query()->delete();
            Unavailability::query()->delete();
            DB::table('notifications')->whereIn('type', [HiveNotice::class, CallSheetIssued::class])->delete();
        });

        $disk->delete($files->all());
        $this->callSilently('db:seed', ['--class' => DemoEventsSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => DemoResourcingSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => DemoTimeSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => DemoFinanceSeeder::class, '--force' => true]);
        $this->info('Demo data refreshed.');

        return self::SUCCESS;
    }
}
