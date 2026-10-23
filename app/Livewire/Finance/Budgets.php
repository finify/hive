<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\PaginatesCollections;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Budgets')]
class Budgets extends Component
{
    use PaginatesCollections, UsesWorkspace;

    #[Url(except: 'active')]
    public string $scope = 'active';

    public function mount(): void
    {
        $this->sort = $this->sort ?: 'start';
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();

        $rows = $viewer->events()
            ->filter(fn ($e) => $viewer->canSeeBudget($e) && $workspace->budget($e['id']))
            ->when($this->scope === 'active', fn ($c) => $c->whereIn('stage', ['confirmed', 'pre-production', 'live', 'wrap']))
            ->map(fn ($e) => ['event' => $e, 'f' => $workspace->financials($e['id'])]);

        $rows = $this->applySort($rows, [
            'start' => fn ($r) => $r['event']['start_date'],
            'budget' => fn ($r) => $r['f']->budget->minor,
            'forecast' => fn ($r) => $r['f']->forecast()->minor,
            'margin' => fn ($r) => $r['f']->marginPercent() ?? 0,
            'health' => fn ($r) => match ($r['f']->health?->value) {
                'at-risk' => 0, 'watch' => 1, 'on-track' => 2, default => 3
            },
        ]);

        $totals = [
            'revenue' => Money::sum($rows->map(fn ($r) => $r['f']->revenue)),
            'budget' => Money::sum($rows->map(fn ($r) => $r['f']->budget)),
            'committed' => Money::sum($rows->map(fn ($r) => $r['f']->committed)),
            'actual' => Money::sum($rows->map(fn ($r) => $r['f']->actual)),
            'forecast' => Money::sum($rows->map(fn ($r) => $r['f']->forecast())),
        ];

        return view('livewire.finance.budgets', ['rows' => $this->paginateCollection($rows), 'totals' => $totals, 'count' => $rows->count()]);
    }
}
