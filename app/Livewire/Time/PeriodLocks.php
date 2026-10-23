<?php

namespace App\Livewire\Time;

use App\Actions\Time\LockPeriod;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\PeriodLock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Finance locks timesheets after payroll, and unlocks with a reason.
 */
#[Layout('layouts.app')]
#[Title('Payroll locks')]
class PeriodLocks extends Component
{
    use UsesWorkspace;

    public string $periodStart = '';

    public string $periodEnd = '';

    public ?int $unlockingId = null;

    public string $unlockReason = '';

    public function mount(): void
    {
        $last = $this->workspace()->lockedUntil();
        $next = $last ? CarbonImmutable::parse($last)->addDay() : $this->workspace()->today()->subMonth()->startOfMonth();
        $this->periodStart = $next->toDateString();
        $this->periodEnd = $next->endOfMonth()->min($this->workspace()->today())->toDateString();
    }

    public function lock(LockPeriod $lock): void
    {
        $this->resetValidation();

        try {
            $result = $lock->lock(auth()->user(), ['period_start' => $this->periodStart, 'period_end' => $this->periodEnd]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->toast("Locked. {$result['locked_weeks']} approved ".($result['locked_weeks'] === 1 ? 'week' : 'weeks').' now read Locked.');

        if ($result['pending_entries'] > 0) {
            $this->toast("{$result['pending_entries']} entries in the period are still waiting for approval; they can be approved but not changed.", 'error');
        }
    }

    public function startUnlock(int $lockId): void
    {
        $this->resetValidation();
        $this->unlockingId = $lockId;
        $this->unlockReason = '';
        $this->dispatch('modal', id: 'unlock-modal', show: true);
    }

    public function unlock(LockPeriod $lock): void
    {
        $this->resetValidation();

        try {
            $lock->unlock(auth()->user(), (int) $this->unlockingId, $this->unlockReason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'unlock-modal', show: false);
        $this->toast('Unlocked. The change is in the audit log.');
    }

    public function render(): View
    {
        return view('livewire.time.period-locks', [
            'locks' => PeriodLock::query()->orderByDesc('period_start')->limit(24)->get(),
        ]);
    }
}
