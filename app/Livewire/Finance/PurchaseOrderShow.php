<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\ManagePurchaseOrder;
use App\Actions\Finance\PostCost;
use App\Actions\Finance\SavePurchaseOrder;
use App\Demo\Rows;
use App\Enums\ApprovalStatus;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\Approval;
use App\Models\PurchaseOrder;
use App\Services\Approvals\ApprovalEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One purchase order: its lines, who has signed it off, invoices against
 * it, and what can happen next.
 */
#[Layout('layouts.app')]
class PurchaseOrderShow extends Component
{
    use UsesWorkspace;

    public string $code;

    public string $comment = '';

    public string $reason = '';

    public function mount(string $code): void
    {
        $po = $this->model($code);
        abort_unless(auth()->user()->can('po.view') && $this->viewer()->canSeeBudget($this->workspace()->event($po->event_id) ?? []), 403);
        $this->code = $po->code;
    }

    private function model(?string $code = null): PurchaseOrder
    {
        return PurchaseOrder::query()->with(['lines', 'supplier'])->where('code', $code ?? $this->code)->firstOrFail();
    }

    public function submit(ManagePurchaseOrder $manage): void
    {
        $this->attempt(fn () => $manage->submit(auth()->user(), $this->model()), 'Submitted for approval. The approver has been told.');
    }

    public function decide(bool $approve, ManagePurchaseOrder $manage): void
    {
        $this->resetValidation();

        try {
            $outcome = $manage->decide(auth()->user(), $this->model(), $approve, $this->comment ?: null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->comment = '';
        $this->toast(match ($outcome) {
            ApprovalStatus::Approved => 'Approved. The net amount is now committed on the budget.',
            ApprovalStatus::Rejected => 'Sent back with your comment.',
            default => 'Approved. It now goes to the second approver.',
        });
    }

    public function send(bool $email, ManagePurchaseOrder $manage): void
    {
        $sentTo = null;
        $this->attempt(function () use ($manage, $email, &$sentTo) {
            $sentTo = $manage->send(auth()->user(), $this->model(), $email);
        }, $email ? 'Emailed to the supplier with the PDF.' : 'Marked as sent.');
    }

    public function receiveGoods(ManagePurchaseOrder $manage): void
    {
        $this->attempt(fn () => $manage->receiveGoods(auth()->user(), $this->model()), 'Goods received. Invoices against this PO can now match.');
    }

    public function cancel(ManagePurchaseOrder $manage): void
    {
        $this->resetValidation();

        try {
            $manage->cancel(auth()->user(), $this->model(), $this->reason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->dispatch('modal', id: 'cancel-modal', show: false);
        $this->toast('Cancelled. Its commitment has been released.');
    }

    public function render(ApprovalEngine $engine, PostCost $costs): View
    {
        $po = $this->model();
        $workspace = $this->workspace();
        $user = auth()->user();
        $event = $workspace->event($po->event_id) ?? [];

        return view('livewire.finance.purchase-order-show', [
            'po' => $po,
            'event' => $event,
            'line' => Rows::of($workspace->budget($po->event_id)['lines'] ?? [])->firstWhere('id', $po->budget_line_id),
            'trail' => Approval::query()->where('approvable_type', PurchaseOrder::class)->where('approvable_id', $po->id)->orderBy('id')->get(),
            'invoices' => $workspace->supplierInvoices()->where('purchase_order_id', $po->id)->sortBy('invoice_date'),
            'outstanding' => $costs->outstanding($po->id),
            'canRaise' => app(SavePurchaseOrder::class)->canRaise($user, $event),
            'canDecide' => $engine->decidableBy($user->person_id, $po) !== null,
        ])->title($po->code);
    }
}
