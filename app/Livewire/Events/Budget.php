<?php

namespace App\Livewire\Events;

use App\Actions\Finance\SaveBudget;
use App\Demo\Rows;
use App\Enums\BudgetStatus;
use App\Enums\RevenueType;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Budget as BudgetVersion;
use App\Models\BudgetLine;
use App\Models\RevenueLine;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The event's budget tab: cost lines with committed, actual and forecast,
 * revenue, versions against the approved baseline, and billing milestones.
 */
class Budget extends Component
{
    use UsesWorkspace;

    public int $eventId;

    public ?int $editingLineId = null;

    /** @var array{category: string, description: string, supplier_id: string, amount: string, currency: string, notes: string} */
    public array $lineForm = ['category' => 'production', 'description' => '', 'supplier_id' => '', 'amount' => '', 'currency' => 'GBP', 'notes' => ''];

    /** @var array{type: string, description: string, amount: string} */
    public array $revenueForm = ['type' => 'change-request', 'description' => '', 'amount' => ''];

    public function mount(int $eventId): void
    {
        $this->eventId = $eventId;
    }

    public function startLine(?int $lineId = null): void
    {
        $this->resetValidation();
        $this->editingLineId = $lineId;
        $line = $lineId ? BudgetLine::query()->where('event_id', $this->eventId)->findOrFail($lineId) : null;

        $this->lineForm = $line
            ? ['category' => $line->category, 'description' => $line->description, 'supplier_id' => (string) $line->supplier_id, 'amount' => number_format($line->budget_minor / 100, 2, '.', ''), 'currency' => $line->currency, 'notes' => (string) $line->notes]
            : ['category' => 'production', 'description' => '', 'supplier_id' => '', 'amount' => '', 'currency' => 'GBP', 'notes' => ''];

        $this->dispatch('modal', id: 'line-modal', show: true);
    }

    public function saveLine(SaveBudget $save): void
    {
        $this->resetValidation();
        $line = $this->editingLineId ? BudgetLine::query()->where('event_id', $this->eventId)->findOrFail($this->editingLineId) : null;

        try {
            $save->saveLine(auth()->user(), $this->eventId, array_map(fn ($v) => $v === '' ? null : $v, $this->lineForm), $line);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['lineForm.'.$k => $m])->all());

            return;
        }

        $this->dispatch('modal', id: 'line-modal', show: false);
        $this->toast($line ? 'Line changed. The budget is now a revision until it is approved.' : 'Line added. The budget is now a revision until it is approved.');
    }

    public function removeLine(int $lineId, SaveBudget $save): void
    {
        $line = BudgetLine::query()->where('event_id', $this->eventId)->findOrFail($lineId);
        $this->attempt(fn () => $save->removeLine(auth()->user(), $line), 'Line removed.');
    }

    public function approve(SaveBudget $save): void
    {
        $this->attempt(fn () => $save->approve(auth()->user(), $this->eventId), 'Approved as the new baseline.');
    }

    public function addRevenue(SaveBudget $save): void
    {
        $this->resetValidation();

        try {
            $save->addRevenue(auth()->user(), $this->eventId, $this->revenueForm);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['revenueForm.'.$k => $m])->all());

            return;
        }

        $this->revenueForm = ['type' => 'change-request', 'description' => '', 'amount' => ''];
        $this->toast('Revenue added. Margin updated.');
    }

    public function removeRevenue(int $id, SaveBudget $save): void
    {
        $row = RevenueLine::query()->where('event_id', $this->eventId)->findOrFail($id);
        $this->attempt(fn () => $save->removeRevenue(auth()->user(), $row), 'Revenue removed.');
    }

    public function render(SaveBudget $save): View
    {
        $workspace = $this->workspace();
        $user = auth()->user();
        $budget = $workspace->budget($this->eventId);
        $versions = BudgetVersion::query()->where('event_id', $this->eventId)->orderByDesc('version')->get();
        $baseline = $versions->firstWhere('status', BudgetStatus::Approved);
        $current = (int) Rows::of($budget['lines'] ?? [])->sum('budget_gbp_minor');

        return view('livewire.events.budget', [
            'event' => $workspace->event($this->eventId),
            'budget' => $budget,
            'financials' => $workspace->financials($this->eventId),
            'lines' => $workspace->budgetLines($this->eventId),
            'revenueLines' => $workspace->revenueLines()->where('event_id', $this->eventId),
            'milestones' => $workspace->billingMilestones()->where('event_id', $this->eventId)->sortBy('due_date'),
            'versions' => $versions,
            'baseline' => $baseline,
            'draft' => $versions->firstWhere('status', BudgetStatus::Draft),
            'againstBaseline' => $baseline ? Money::of($current - $baseline->total_gbp_minor) : null,
            'canEdit' => $save->canEdit($user, $this->eventId),
            'canApprove' => $user->can('budgets.approve'),
            'canRaisePo' => $user->can('po.create'),
            'categories' => Rows::of($workspace->organisation()['cost_categories'])->pluck('name', 'key'),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'revenueTypes' => RevenueType::cases(),
            'currencies' => config('hive.finance.currencies'),
        ]);
    }
}
