@php
    use App\Enums\ApprovalStatus;
    use App\Enums\PurchaseOrderStatus;
    use App\Enums\SupplierInvoiceStatus;
    use App\Enums\VatRate;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $status = $po->status;
    $net = Money::of($po->subtotal_minor, $po->currency);
@endphp
<div>
    <x-page-head :title="$po->code.', '.$po->supplier?->name" :answer="$net->format().' net'.($po->currency !== 'GBP' ? ' at '.$po->fx_rate.' to the pound' : '').' against '.($line['description'] ?? 'its budget line').'.'">
        <x-slot:crumbs>
            <a href="{{ route('purchase-orders') }}" wire:navigate>Purchase orders</a> <span aria-hidden="true">/</span> <span class="code">{{ $po->code }}</span>
        </x-slot:crumbs>
        <x-slot:actions>
            <a href="{{ route('purchase-orders.pdf', $po->code) }}" class="btn btn-secondary" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
            @if ($canRaise && $status === PurchaseOrderStatus::Draft)
                <a href="{{ route('purchase-orders.edit', $po->code) }}" class="btn btn-secondary" wire:navigate>Edit</a>
                <button type="button" class="btn btn-primary" wire:click="submit" wire:loading.attr="disabled">Submit for approval</button>
            @endif
            @if ($canRaise && $status === PurchaseOrderStatus::Approved)
                <button type="button" class="btn btn-secondary" wire:click="send(false)" wire:loading.attr="disabled">Mark as sent</button>
                <button type="button" class="btn btn-primary" wire:click="send(true)" wire:loading.attr="disabled" wire:confirm="Email this PO to the supplier’s primary contact?">Email to supplier</button>
            @endif
            @if ($canRaise && ! $po->goods_received_at && in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyInvoiced], true))
                <button type="button" class="btn btn-secondary" wire:click="receiveGoods" wire:loading.attr="disabled">Goods received</button>
            @endif
            @if ($canRaise && in_array($status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent], true))
                <button type="button" class="btn btn-quiet text-negative" data-bs-toggle="modal" data-bs-target="#cancel-modal">Cancel PO</button>
            @endif
        </x-slot:actions>
    </x-page-head>

    <div class="gap-row mb-3">
        <x-status :value="$status" />
        <span class="text-muted"><x-event-code :event="$event" /> {{ $event['name'] ?? '' }}</span>
        <span class="text-muted">Raised by {{ $workspace->personName($po->raised_by) }}, {{ Display::date($po->created_at) }}</span>
        @if ($po->goods_received_at)<span class="text-muted">Goods received {{ Display::date($po->goods_received_at) }}</span>@endif
        @if ($po->sent_at)<span class="text-muted">Sent {{ Display::date($po->sent_at) }}</span>@endif
    </div>

    @if ($canDecide)
        <section class="card mb-3">
            <div class="card-header"><h2 class="card-title">Waiting for your approval</h2></div>
            <div class="card-body d-grid gap-2">
                <p class="mb-0">Approving commits {{ $net->format() }} against {{ $line['description'] ?? 'the budget line' }}, which has {{ isset($line) ? Money::of($line['remaining_gbp_minor'])->format() : '—' }} of its estimate left to commit.</p>
                <label for="po-comment" class="form-label mb-0">Comment (needed to send it back)</label>
                <textarea id="po-comment" rows="2" @class(['form-control', 'is-invalid' => $errors->has('comment')]) wire:model="comment"></textarea>
                @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" wire:click="decide(true)" wire:loading.attr="disabled">Approve</button>
                    <button type="button" class="btn btn-quiet" wire:click="decide(false)" wire:loading.attr="disabled">Send back</button>
                </div>
            </div>
        </section>
    @endif

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header"><h2 class="card-title">Lines</h2></div>
            <div class="table-wrap border-0 rounded-0">
                <table class="table">
                    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th>VAT</th><th class="num">Net</th></tr></thead>
                    <tbody>
                        @foreach ($po->lines as $l)
                            <tr>
                                <td>{{ $l->description }}</td>
                                <td class="num">{{ rtrim(rtrim((string) $l->quantity, '0'), '.') }}</td>
                                <td class="num">{{ Money::of($l->unit_price_minor, $po->currency)->format() }}</td>
                                <td>{{ VatRate::tryFrom($l->vat_rate)?->label() ?? $l->vat_rate }}</td>
                                <td class="num">{{ Money::of($l->unit_price_minor, $po->currency)->multiplyRatio((int) round((float) $l->quantity * 100), 100)->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-end text-muted">Net</td><td class="num">{{ $net->format() }}</td></tr>
                        <tr><td colspan="4" class="text-end text-muted">VAT</td><td class="num">{{ Money::of($po->vat_minor, $po->currency)->format() }}</td></tr>
                        <tr><td colspan="4" class="text-end fw-medium">Total</td><td class="num fw-medium">{{ Money::of($po->total_minor, $po->currency)->format() }}</td></tr>
                    </tfoot>
                </table>
            </div>
            @if ($po->notes)
                <div class="card-body"><p class="note small mb-0">{{ $po->notes }}</p></div>
            @endif
        </section>

        <div class="span-4 d-grid gap-3 align-content-start">
            <section class="card">
                <div class="card-header"><h2 class="card-title">Approvals</h2></div>
                @if ($trail->isEmpty())
                    <x-empty title="Not submitted yet">Submit it and it goes to the right approver for its value.</x-empty>
                @else
                    <ul class="list-rows">
                        @foreach ($trail as $a)
                            <li>
                                <div class="row-main">
                                    <div class="row-title">{{ $workspace->personName($a->approver_id) }}@if ($trail->max('step') > 1) <span class="small text-muted">step {{ $a->step }}</span>@endif</div>
                                    <div class="row-sub">{{ $a->decided_at ? Display::dateTime($a->decided_at) : 'Since '.Display::date($a->created_at) }}@if ($a->decided_by && $a->decided_by !== $a->approver_id), by {{ $workspace->personName($a->decided_by) }}@endif</div>
                                    @if ($a->comment)<div class="note small mt-1">{{ $a->comment }}</div>@endif
                                </div>
                                <x-status :value="$a->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card">
                <div class="card-header"><h2 class="card-title">Invoices</h2>@if (in_array($status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyInvoiced], true))<span class="small text-muted ms-auto tnum">{{ $outstanding->format() }} still committed</span>@endif</div>
                @if ($invoices->isEmpty())
                    <x-empty title="None yet">Supplier invoices against this PO are matched here.</x-empty>
                @else
                    <ul class="list-rows">
                        @foreach ($invoices as $si)
                            <li>
                                <div class="row-main"><div class="row-title code">{{ $si['invoice_number'] }}</div><div class="row-sub">{{ Display::date($si['invoice_date']) }}</div></div>
                                <span class="tnum small">{{ Money::of($si['net_minor'], $si['currency'])->format() }}</span>
                                <x-status :value="SupplierInvoiceStatus::from($si['status'])" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    <x-modal id="cancel-modal" title="Cancel this PO">
        <label for="po-reason" class="form-label">Why is it cancelled?</label>
        <textarea id="po-reason" rows="2" @class(['form-control', 'is-invalid' => $errors->has('reason')]) wire:model="reason"></textarea>
        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <p class="small text-muted mt-2 mb-0">Anything still committed is released and the line’s estimate goes back up.</p>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep it</button>
            <button type="button" class="btn btn-primary" wire:click="cancel" wire:loading.attr="disabled">Cancel PO</button>
        </x-slot:footer>
    </x-modal>
</div>
