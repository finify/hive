<?php

namespace App\Livewire\Reports;

use App\Reports\Report;
use App\Reports\Reports;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One report: filters, the table, and the same rows as CSV.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    public string $key;

    /** @var array<string, string> */
    #[Url(as: 'f')]
    public array $filters = [];

    public function mount(string $key): void
    {
        abort_unless($this->report($key) !== null, 404);
        $this->key = $key;
    }

    private function report(?string $key = null): ?Report
    {
        return Reports::find(auth()->user(), $key ?? $this->key);
    }

    public function render(): View
    {
        $report = $this->report() ?? abort(403);
        $filters = $report->resolve($this->filters);
        $rows = $report->rows($filters);

        return view('livewire.reports.show', [
            'report' => $report,
            'applied' => $filters,
            'rows' => $rows,
            'totals' => $report->totals($rows),
            'csvUrl' => route('reports.csv', ['key' => $this->key] + array_filter($filters)),
        ])->title($report->title());
    }
}
