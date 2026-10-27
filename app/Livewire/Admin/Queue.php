<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Background jobs that failed, with the error, to retry or clear (section
 * 14: failed jobs visible to admins).
 */
#[Layout('layouts.app')]
#[Title('Queue')]
class Queue extends Component
{
    use UsesWorkspace;

    public function retry(string $uuid): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->log('queue-retried', 'Retried a failed job', $uuid);
        $this->toast('Sent back to the queue.');
    }

    public function forget(string $uuid): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->log('queue-forgotten', 'Cleared a failed job', $uuid);
        $this->toast('Cleared.');
    }

    private function log(string $event, string $message, string $uuid): void
    {
        activity('settings')->causedBy(auth()->user())->withProperties(['ref' => $uuid])->event($event)->log($message);
    }

    public function render(): View
    {
        $failed = DB::table('failed_jobs')->latest('failed_at')->limit(50)->get()->map(function ($row) {
            $payload = json_decode((string) $row->payload, true);

            return [
                'uuid' => (string) $row->uuid,
                'job' => class_basename((string) ($payload['displayName'] ?? 'Job')),
                'queue' => (string) $row->queue,
                'error' => strtok((string) $row->exception, "\n") ?: 'Unknown error',
                'failed_at' => (string) $row->failed_at,
            ];
        });

        return view('livewire.admin.queue', [
            'failed' => $failed,
            'waiting' => config('queue.default') === 'database' ? DB::table('jobs')->count() : null,
            'canManage' => auth()->user()->can('settings.manage'),
        ]);
    }
}
