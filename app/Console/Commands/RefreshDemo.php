<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Event;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Unavailability;
use App\Notifications\CallSheetIssued;
use App\Notifications\HiveNotice;
use Database\Seeders\DemoEventsSeeder;
use Database\Seeders\DemoResourcingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Events (phase 2) and resourcing (phase 3) live in the database, so changes
 * made on the demo are shared by every visitor until this puts the seeded
 * data back. Scheduled hourly while demo mode is on; never runs in
 * production.
 */
class RefreshDemo extends Command
{
    protected $signature = 'hive:demo-refresh';

    protected $description = 'Put the demo events, resourcing and notices back to the seeded state';

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
            LeaveRequest::query()->delete();
            Unavailability::query()->delete();
            DB::table('notifications')->whereIn('type', [HiveNotice::class, CallSheetIssued::class])->delete();
        });

        $disk->delete($files->all());
        $this->callSilently('db:seed', ['--class' => DemoEventsSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => DemoResourcingSeeder::class, '--force' => true]);
        $this->info('Demo data refreshed.');

        return self::SUCCESS;
    }
}
