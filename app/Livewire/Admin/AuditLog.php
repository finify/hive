<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\UsesWorkspace;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Every change made in Hive, newest first. Finance sees finance records and
 * HR sees people records; admins and the operations director see all.
 */
#[Layout('layouts.app')]
#[Title('Audit log')]
class AuditLog extends Component
{
    use UsesWorkspace, WithPagination;

    #[Url(except: '')]
    public string $log = '';

    #[Url(except: '')]
    public string $causer = '';

    #[Url(except: '')]
    public string $from = '';

    /**
     * @return list<string>|null null means every log
     */
    private function visibleLogs(): ?array
    {
        $user = auth()->user();

        if ($user->hasAnyRole(['super-admin', 'operations-director'])) {
            return null;
        }

        $logs = [];

        if ($user->hasRole('finance')) {
            array_push($logs, 'finance', 'time');
        }

        if ($user->hasRole('hr')) {
            array_push($logs, 'people', 'identity');
        }

        return $logs;
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $logs = $this->visibleLogs();

        $entries = Activity::query()
            ->with('causer')
            ->when($logs !== null, fn ($q) => $q->whereIn('log_name', $logs))
            ->when($this->log !== '', fn ($q) => $q->where('log_name', $this->log))
            ->when($this->causer !== '', fn ($q) => $q->where('causer_type', User::class)->where('causer_id', (int) $this->causer))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from))
            ->latest('id')
            ->paginate(25);

        return view('livewire.admin.audit-log', [
            'entries' => $entries,
            'logOptions' => $logs ?? ['identity', 'events', 'resourcing', 'time', 'finance', 'people', 'demo'],
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'seeded' => $this->log === '' || $logs === null ? collect($this->workspace()->seededActivity()) : collect(),
        ]);
    }
}
