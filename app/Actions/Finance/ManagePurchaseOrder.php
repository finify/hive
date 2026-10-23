<?php

namespace App\Actions\Finance;

use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Approval;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Notifications\PurchaseOrderIssued;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A PO's life after it is raised: submitted for approval within the limits,
 * approved (adding to committed spend) or sent back, sent to the supplier,
 * goods received, cancelled.
 */
class ManagePurchaseOrder
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ApprovalEngine $approvals,
        private readonly PostCost $costs,
        private readonly SavePurchaseOrder $save,
        private readonly RecordSupplierInvoice $invoices,
    ) {}

    public function submit(User $user, PurchaseOrder $po): void
    {
        $this->authoriseRaiser($user, $po);
        $this->expect($po, [PurchaseOrderStatus::Draft], 'Only a draft PO can be submitted.');

        $approvers = DB::transaction(function () use ($po) {
            $po->update(['status' => PurchaseOrderStatus::PendingApproval]);

            return $this->approvals->request($po);
        });

        $this->workspace->forget();
        $this->log($user, $po, PurchaseOrderStatus::Draft, 'po-submitted', "Submitted {$po->code} for approval");

        foreach ($approvers->where('status', ApprovalStatus::Pending) as $approval) {
            $this->notifyApprover($approval->approver_id, $po);
        }
    }

    /**
     * Approves or sends back. Returns Approved once every approval needed is
     * in, Pending while a second approver is still to sign, Rejected when
     * sent back.
     */
    public function decide(User $user, PurchaseOrder $po, bool $approve, ?string $comment = null): ApprovalStatus
    {
        $this->expect($po, [PurchaseOrderStatus::PendingApproval], 'This PO isn’t waiting for approval.');

        if (! $approve) {
            Validator::make(['comment' => $comment], ['comment' => ['required', 'string', 'min:5', 'max:500']], ['comment.required' => 'Say why it is going back.', 'comment.min' => 'Say why it is going back.'])->validate();
        }

        $outcome = DB::transaction(function () use ($user, $po, $approve, $comment) {
            $outcome = $this->approvals->decide((int) $user->person_id, $po, $approve, $comment);

            if ($outcome === ApprovalStatus::Approved) {
                $po->update(['status' => PurchaseOrderStatus::Approved, 'approved_by' => $user->person_id, 'approved_at' => now()]);
                $this->costs->commit($po);
            } elseif ($outcome === ApprovalStatus::Rejected) {
                $po->update(['status' => PurchaseOrderStatus::Draft]);
            }

            return $outcome;
        });

        $this->workspace->forget();
        $net = Money::of($po->subtotal_minor, $po->currency)->format();

        match ($outcome) {
            ApprovalStatus::Approved => $this->log($user, $po, PurchaseOrderStatus::PendingApproval, 'po-approved', "Approved {$po->code} for {$net}; added to committed spend"),
            ApprovalStatus::Rejected => $this->log($user, $po, PurchaseOrderStatus::PendingApproval, 'po-returned', "Sent {$po->code} back: {$comment}"),
            default => $this->log($user, $po, PurchaseOrderStatus::PendingApproval, 'po-approved-step', "Approved {$po->code}; it needs a second approver"),
        };

        match ($outcome) {
            ApprovalStatus::Approved => $this->workspace->notify($po->raised_by, 'po', "{$po->code} approved", "{$net} is now committed. Send it to the supplier.", 'events'),
            ApprovalStatus::Rejected => $this->workspace->notify($po->raised_by, 'po', "{$po->code} sent back", (string) $comment, 'events'),
            default => Approval::query()->where('approvable_type', PurchaseOrder::class)->where('approvable_id', $po->id)->where('status', ApprovalStatus::Pending)
                ->pluck('approver_id')->each(fn ($id) => $this->notifyApprover((int) $id, $po)),
        };

        return $outcome;
    }

    /**
     * Marks the PO sent, and emails it to the supplier's primary contact
     * when asked.
     */
    public function send(User $user, PurchaseOrder $po, bool $email): ?string
    {
        $this->authoriseRaiser($user, $po);
        $this->expect($po, [PurchaseOrderStatus::Approved], 'Only an approved PO can be sent.');

        $contact = $po->supplier()->with('contacts')->first()?->contacts->firstWhere('is_primary', true);

        if ($email && ($contact === null || ! $contact->email)) {
            throw ValidationException::withMessages(['email' => 'The supplier has no primary contact email. Add one in the directory, or mark it sent.']);
        }

        $po->update(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);
        $this->workspace->forget();

        if ($email && $contact !== null) {
            Notification::route('mail', $contact->email)->notify(new PurchaseOrderIssued($po->code, $this->pdf($po), $this->workspace->organisation()['name']));
        }

        $this->log($user, $po, PurchaseOrderStatus::Approved, 'po-sent', "Sent {$po->code}".($email && $contact ? " to {$contact->email}" : ''));

        return $email ? $contact?->email : null;
    }

    public function receiveGoods(User $user, PurchaseOrder $po): void
    {
        $this->authoriseRaiser($user, $po);

        if ($po->goods_received_at !== null || ! in_array($po->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyInvoiced], true)) {
            throw ValidationException::withMessages(['po' => 'Goods can be received only on an approved PO, once.']);
        }

        $po->update(['goods_received_at' => now(), 'goods_received_by' => $user->person_id]);
        $this->workspace->forget();

        // Invoices waiting on delivery may now match.
        foreach (SupplierInvoice::query()->where('purchase_order_id', $po->id)->where('status', SupplierInvoiceStatus::Received)->get() as $invoice) {
            $this->invoices->match($invoice);
        }

        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->performedOn($po)
            ->withProperties(['ref' => $po->code, 'attributes' => ['goods_received' => true]])
            ->event('goods-received')
            ->log("Confirmed goods received on {$po->code}");
    }

    public function cancel(User $user, PurchaseOrder $po, string $reason): void
    {
        $this->authoriseRaiser($user, $po);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Say why it is cancelled.', 'reason.min' => 'Say why it is cancelled.'])->validate();

        $this->expect($po, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent], 'An invoiced PO can’t be cancelled.');

        $old = $po->status;

        DB::transaction(function () use ($po, $reason) {
            $this->approvals->withdraw($po);
            $this->costs->release($po, $this->costs->outstanding($po->id), $po->code.' cancelled', giveBackEstimate: true);
            $po->update(['status' => PurchaseOrderStatus::Cancelled, 'notes' => trim(($po->notes ? $po->notes."\n" : '').'Cancelled: '.$reason)]);
        });

        $this->workspace->forget();
        $this->log($user, $po, $old, 'po-cancelled', "Cancelled {$po->code}: {$reason}");
    }

    public function pdf(PurchaseOrder $po): string
    {
        $po->loadMissing(['lines', 'supplier.contacts']);

        return Pdf::loadView('pdf.purchase-order', [
            'po' => $po,
            'event' => $this->workspace->event($po->event_id),
            'organisation' => $this->workspace->organisation(),
            'raisedBy' => $this->workspace->personName($po->raised_by),
            'approvedBy' => $po->approved_by ? $this->workspace->personName($po->approved_by) : null,
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    private function authoriseRaiser(User $user, PurchaseOrder $po): void
    {
        abort_unless($this->save->canRaise($user, $this->workspace->event($po->event_id) ?? []), 403);
    }

    /**
     * @param  list<PurchaseOrderStatus>  $statuses
     */
    private function expect(PurchaseOrder $po, array $statuses, string $message): void
    {
        if (! in_array($po->status, $statuses, true)) {
            throw ValidationException::withMessages(['po' => $message]);
        }
    }

    private function notifyApprover(int $personId, PurchaseOrder $po): void
    {
        $this->workspace->notify($personId, 'approval', "{$po->code} to approve", $this->workspace->supplierName($po->supplier_id).', '.Money::of($po->subtotal_minor, $po->currency)->format().' net.', 'approvals');
    }

    private function log(User $user, PurchaseOrder $po, PurchaseOrderStatus $old, string $event, string $message): void
    {
        activity('finance')
            ->causedBy($user)
            ->performedOn($po)
            ->withProperties(['ref' => $po->code, 'old' => ['status' => $old->value], 'attributes' => ['status' => $po->status->value]])
            ->event($event)
            ->log($message);
    }
}
