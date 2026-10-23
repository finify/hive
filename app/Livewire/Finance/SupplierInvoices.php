<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ApproveSupplierInvoice;
use App\Enums\HealthStatus;
use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Finance\InvoiceTolerance;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Supplier invoices')]
class SupplierInvoices extends Component
{
    use UsesWorkspace;

    #[Url(except: 'open')]
    public string $show = 'open';

    #[Url(as: 'invoice', except: null)]
    public ?int $reviewing = null;

    public string $note = '';

    public function mount(): void
    {
        if ($this->reviewing) {
            $this->dispatch('modal', id: 'invoice-modal', show: true);
        }
    }

    public function review(int $id): void
    {
        $this->reviewing = $id;
        $this->note = '';
        $this->resetValidation();
        $this->dispatch('modal', id: 'invoice-modal', show: true);
    }

    public function approve(ApproveSupplierInvoice $approve): void
    {
        $this->resetValidation();
        $invoice = $this->workspace()->supplierInvoices()->get((int) $this->reviewing);
        $before = $invoice ? $this->workspace()->financials($invoice['event_id']) : null;

        try {
            $movement = $approve->handle(auth()->user(), (int) $this->reviewing, $this->note ?: null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $after = $this->workspace()->financials($invoice['event_id']);
        $this->dispatch('modal', id: 'invoice-modal', show: false);
        $this->reviewing = null;
        $this->toast(sprintf(
            'Approved. Forecast moved by %s; margin %s%% to %s%%%s.',
            $movement->format(),
            $before?->marginPercent(),
            $after?->marginPercent(),
            $before && $after && $before->health !== $after->health ? ', now '.($after->health === HealthStatus::Watch ? 'on watch' : mb_strtolower((string) $after->health?->label())) : '',
        ));
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();

        $invoices = $workspace->supplierInvoices()
            ->filter(fn ($si) => ($event = $workspace->event($si['event_id'])) && $viewer->canSeeBudget($event))
            ->when($this->show === 'open', fn ($c) => $c->whereIn('status', ['received', 'matched', 'disputed']))
            ->sortBy('invoice_date');

        $current = $this->reviewing ? $workspace->supplierInvoices()->get($this->reviewing) : null;
        $po = $current ? $workspace->purchaseOrders()->get($current['purchase_order_id']) : null;
        $allowance = $po ? InvoiceTolerance::fromConfig()->allowance(Money::of($po['subtotal_minor'], $po['currency'])) : null;

        return view('livewire.finance.supplier-invoices', [
            'invoices' => $invoices,
            'current' => $current,
            'po' => $po,
            'allowance' => $allowance,
            'financials' => $current ? $workspace->financials($current['event_id']) : null,
            'canApprove' => $current && auth()->user()->can('supplier-invoice.approve', [$current]),
        ]);
    }
}
