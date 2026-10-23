<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Event;
use App\Models\Task;
use App\Models\TaskTemplate;
use Database\Seeders\DemoEventsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Events live in the database from phase 2, so changes made on the demo are
 * shared by every visitor until this puts the seeded events back. Scheduled
 * nightly while demo mode is on; never runs in production.
 */
class RefreshDemoEvents extends Command
{
    protected $signature = 'hive:demo-refresh';

    protected $description = 'Put the demo events, tasks and event files back to the seeded state';

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
            Event::withTrashed()->forceDelete();
        });

        $disk->delete($files->all());
        $this->callSilently('db:seed', ['--class' => DemoEventsSeeder::class, '--force' => true]);
        $this->info('Demo events refreshed.');

        return self::SUCCESS;
    }
}
