<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Client billing')]
class Billing extends Component
{
    use UsesWorkspace;

    public function render(): View
    {
        $workspace = $this->workspace();
        $milestones = $workspace->billingMilestones()->sortBy('due_date');
        $today = $workspace->today();

        // Expected inflows by month: everything not yet paid, by due month.
        $months = [];

        for ($i = -1; $i < 5; $i++) {
            $month = $today->startOfMonth()->addMonths($i);
            $months[$month->format('M Y')] = Money::of((int) $milestones
                ->filter(fn ($m) => $m['status'] !== 'paid' && str_starts_with($m['due_date'], $month->format('Y-m')))
                ->sum('amount_minor'));
        }

        return view('livewire.finance.billing', [
            'milestones' => $milestones->filter(fn ($m) => $m['status'] !== 'paid' || CarbonImmutable::parse($m['due_date'])->greaterThan($today->subMonths(2))),
            'months' => $months,
            'overdue' => $milestones->where('status', 'overdue'),
        ]);
    }
}
