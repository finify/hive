<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ManageBilling;
use App\Enums\EventStage;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Client billing')]
class Billing extends Component
{
    use UsesWorkspace;

    /** @var array{event_id: string, description: string, percentage: string, amount: string, due_date: string} */
    public array $milestone = ['event_id' => '', 'description' => '', 'percentage' => '', 'amount' => '', 'due_date' => ''];

    public ?int $invoicingId = null;

    public string $reference = '';

    public function addMilestone(ManageBilling $billing): void
    {
        $this->resetValidation();

        try {
            $billing->addMilestone(auth()->user(), array_map(fn ($v) => $v === '' ? null : $v, $this->milestone));
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['milestone.'.$k => $m])->all());

            return;
        }

        $this->milestone = ['event_id' => $this->milestone['event_id'], 'description' => '', 'percentage' => '', 'amount' => '', 'due_date' => ''];
        $this->toast('Milestone added.');
    }

    public function startInvoice(int $id): void
    {
        $this->resetValidation();
        $this->invoicingId = $id;
        $this->reference = '';
        $this->dispatch('modal', id: 'invoice-ref-modal', show: true);
    }

    public function markInvoiced(ManageBilling $billing): void
    {
        $this->resetValidation();

        try {
            $billing->markInvoiced(auth()->user(), (int) $this->invoicingId, $this->reference, $this->workspace()->today()->toDateString());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'invoice-ref-modal', show: false);
        $this->toast('Recorded as invoiced.');
    }

    public function markPaid(int $id, ManageBilling $billing): void
    {
        $this->attempt(fn () => $billing->markPaid(auth()->user(), $id, $this->workspace()->today()->toDateString()), 'Recorded as paid.');
    }

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
            'canManage' => app(ManageBilling::class)->canManage(auth()->user()),
            'billableEvents' => $workspace->events()->filter(fn ($e) => EventStage::from($e['stage'])->isConfirmedOrLater() && ! EventStage::from($e['stage'])->isTerminal())->sortBy('code'),
        ]);
    }
}
