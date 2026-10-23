<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ApproveSupplierInvoice;
use App\Actions\Finance\PostCost;
use App\Actions\Finance\RecordSupplierInvoice;
use App\Actions\Finance\SettleSupplierInvoice;
use App\Demo\Rows;
use App\Enums\HealthStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\VatRate;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\PurchaseOrder;
use App\Support\Finance\InvoiceTolerance;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Supplier invoices')]
class SupplierInvoices extends Component
{
    use UsesWorkspace, WithFileUploads;

    #[Url(except: 'open')]
    public string $show = 'open';

    #[Url(as: 'invoice', except: null)]
    public ?int $reviewing = null;

    public string $note = '';

    /** @var array<string, string> */
    public array $record = [];

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public function startRecord(): void
    {
        $this->resetValidation();
        $this->record = [
            'purchase_order_id' => '', 'supplier_id' => '', 'event_id' => '', 'budget_line_id' => '', 'no_po_reason' => '',
            'invoice_number' => '', 'invoice_date' => $this->workspace()->today()->toDateString(), 'due_date' => $this->workspace()->today()->addDays(30)->toDateString(),
            'currency' => 'GBP', 'net' => '', 'vat_rate' => '20', 'notes' => '',
        ];
        $this->dispatch('modal', id: 'record-modal', show: true);
    }

    public function updatedRecordPurchaseOrderId(mixed $value): void
    {
        $po = $value ? $this->workspace()->purchaseOrders()->get((int) $value) : null;

        if ($po) {
            $this->record['currency'] = $po['currency'];
        }
    }

    public function saveRecord(RecordSupplierInvoice $recordInvoice): void
    {
        $this->resetValidation();

        try {
            $invoice = $recordInvoice->handle(auth()->user(), array_map(fn ($v) => $v === '' ? null : $v, $this->record) + ['file' => $this->upload]);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => [($k === 'file' ? 'upload' : 'record.'.$k) => $m])->all());

            return;
        }

        $this->reset('upload');
        $this->dispatch('modal', id: 'record-modal', show: false);
        $this->toast("Recorded {$invoice->invoice_number}: ".($invoice->status === SupplierInvoiceStatus::Matched ? 'matched to its PO.' : 'waiting for checks before approval.'));
    }

    public function dispute(SettleSupplierInvoice $settle): void
    {
        $this->resetValidation();

        try {
            $settle->dispute(auth()->user(), (int) $this->reviewing, $this->note);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'invoice-modal', show: false);
        $this->reviewing = null;
        $this->toast('Disputed. The producer has been told.');
    }

    public function reopen(SettleSupplierInvoice $settle): void
    {
        $this->attempt(fn () => $settle->reopen(auth()->user(), (int) $this->reviewing), 'Reopened for approval.');
    }

    public function markPaid(SettleSupplierInvoice $settle): void
    {
        $this->attempt(fn () => $settle->markPaid(auth()->user(), (int) $this->reviewing, $this->workspace()->today()->toDateString()), 'Recorded as paid.');
    }

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

    public function render(RecordSupplierInvoice $recordInvoice, PostCost $costs): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();

        $invoices = $workspace->supplierInvoices()
            ->filter(fn ($si) => ($event = $workspace->event($si['event_id'])) && $viewer->canSeeBudget($event))
            ->when($this->show === 'open', fn ($c) => $c->whereIn('status', ['received', 'matched', 'disputed']))
            ->sortBy('invoice_date');

        $current = $this->reviewing ? $workspace->supplierInvoices()->get($this->reviewing) : null;
        $po = $current ? $workspace->purchaseOrders()->get($current['purchase_order_id']) : null;
        $balance = $po ? $recordInvoice->uninvoiced(PurchaseOrder::query()->findOrFail($po['id'])) : null;
        $allowance = $balance ? InvoiceTolerance::fromConfig()->allowance($balance) : null;
        $netGbp = $current ? $costs->toGbp(Money::of($current['net_minor'], $current['currency']), $current['fx_rate']) : null;
        $release = $po && $netGbp ? Money::of(min($netGbp->minor, $costs->outstanding($po['id'])->minor)) : Money::zero();

        return view('livewire.finance.supplier-invoices', [
            'invoices' => $invoices,
            'current' => $current,
            'po' => $po,
            'allowance' => $allowance,
            'financials' => $current ? $workspace->financials($current['event_id']) : null,
            'canApprove' => $current && auth()->user()->can('supplier-invoice.approve', [$current]),
            'canEdit' => auth()->user()->can('supplier_invoices.edit'),
            'balance' => $balance,
            'netGbp' => $netGbp,
            'release' => $release,
            'openPos' => $workspace->purchaseOrders()->filter(fn ($p) => in_array($p['status'], ['approved', 'sent', 'partially-invoiced'], true))->sortBy('code'),
            'recordEvents' => $workspace->events()->filter(fn ($e) => $workspace->budget($e['id']) !== null)->sortBy('code'),
            'recordLines' => ($this->record['event_id'] ?? '') !== '' ? Rows::of($workspace->budget((int) $this->record['event_id'])['lines'] ?? []) : collect(),
            'suppliersList' => $workspace->suppliers()->sortBy('name'),
            'vatRates' => VatRate::cases(),
        ]);
    }
}
