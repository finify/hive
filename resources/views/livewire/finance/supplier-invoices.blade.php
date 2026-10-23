@php
    use App\Enums\SupplierInvoiceStatus;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $over = $invoices->filter(fn ($si) => $workspace->invoiceOverTolerance($si) && ! in_array($si['status'], ['approved', 'paid'], true));
@endphp
<div>
    <x-page-head title="Supplier invoices" :answer="$invoices->count().' '.($show === 'open' ? 'open invoices' : 'invoices').($over->isNotEmpty() ? ', '.$over->count().' over tolerance against their PO.' : '.').' Approving moves the cost from committed to actual.'">
        <x-slot:actions>
            @if ($canEdit)
                <button type="button" class="btn btn-primary" wire:click="startRecord">Record an invoice</button>
            @endif
            <div class="btn-group" role="group" aria-label="Which invoices">
                <button type="button" @class(['btn btn-secondary', 'active' => $show === 'open']) wire:click="$set('show', 'open')">Open</button>
                <button type="button" @class(['btn btn-secondary', 'active' => $show === 'all']) wire:click="$set('show', 'all')">All</button>
            </div>
        </x-slot:actions>
    </x-page-head>

    <div class="table-wrap">
        @if ($invoices->isEmpty())
            <x-empty title="No open invoices">Invoices recorded against purchase orders appear here. Hive compares each one with its PO and flags any difference over tolerance.</x-empty>
        @else
            <table class="table table-hover">
                <thead><tr><th>Invoice</th><th>Supplier</th><th>Event</th><th>PO</th><th>Received</th><th class="num">Net</th><th class="num">Variance</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($invoices as $si)
                        @php
                            $isOver = $workspace->invoiceOverTolerance($si);
                        @endphp
                        <tr wire:key="si-{{ $si['id'] }}">
                            <td class="code">{{ $si['invoice_number'] }}</td>
                            <td>{{ $workspace->suppliers()->get($si['supplier_id'])['name'] }}</td>
                            <td><x-event-code :event="$workspace->event($si['event_id'])" /></td>
                            <td class="code">{{ $workspace->purchaseOrders()->get($si['purchase_order_id'])['code'] ?? 'No PO' }}</td>
                            <td class="tnum">{{ Display::date($si['invoice_date']) }}</td>
                            <td class="num">{{ Money::of($si['net_minor'], $si['currency'])->format() }}</td>
                            <td @class(['num', 'text-negative fw-medium' => $isOver && $si['variance_minor'] > 0])>{{ $si['variance_minor'] ? Money::of($si['variance_minor'], $si['currency'])->format() : '—' }}</td>
                            <td>
                                <x-status :value="SupplierInvoiceStatus::from($si['status'])" />
                                @if ($isOver && ! in_array($si['status'], ['approved', 'paid'], true))
                                    <x-pill tone="negative">Over tolerance</x-pill>
                                @endif
                            </td>
                            <td class="text-end"><button type="button" class="btn btn-secondary btn-sm" wire:click="review({{ $si['id'] }})">Review</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <x-modal id="invoice-modal" :title="$current ? 'Invoice '.$current['invoice_number'] : 'Invoice'" size="lg">
        @if ($current)
            @php
                $isOver = $workspace->invoiceOverTolerance($current);
                $event = $workspace->event($current['event_id']);
                $net = Money::of($current['net_minor'], $current['currency']);
                $open = in_array($current['status'], ['received', 'matched'], true);
            @endphp
            <dl class="kv mb-3">
                <dt>Supplier</dt><dd>{{ $workspace->supplierName($current['supplier_id']) }}</dd>
                <dt>Event</dt><dd><span class="code">{{ $event['code'] ?? '' }}</span> {{ $event['name'] ?? '' }}</dd>
                @if ($po)
                    <dt>Purchase order</dt><dd><a href="{{ route('purchase-orders.show', $po['code']) }}" class="code" wire:navigate>{{ $po['code'] }}</a> {{ $po['lines'][0]['description'] ?? '' }}</dd>
                    <dt>Goods received</dt><dd>{{ $po['goods_received'] ? 'Confirmed' : 'Not confirmed yet' }}</dd>
                @else
                    <dt>Purchase order</dt><dd>None. {{ $current['no_po_reason'] }}</dd>
                @endif
                <dt>Status</dt><dd><x-status :value="SupplierInvoiceStatus::from($current['status'])" /></dd>
            </dl>
            @if ($po && $balance)
                <div class="table-wrap mb-3">
                    <table class="table">
                        <tbody>
                            <tr><td>Left to invoice on the PO</td><td class="num">{{ $balance->format() }}</td></tr>
                            <tr><td>Invoice net</td><td class="num">{{ $net->format() }}</td></tr>
                            <tr class="fw-medium"><td>Difference</td><td @class(['num', 'text-negative' => $isOver])>{{ $net->minus($balance)->format() }}</td></tr>
                            <tr><td class="text-muted">Tolerance (5% or £250, whichever is lower)</td><td class="num text-muted">{{ $allowance?->format() }}</td></tr>
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($current['notes'])
                <p class="note small">{{ $current['notes'] }}</p>
            @endif
            @if ($financials && $open && $netGbp)
                @php
                    $movement = $netGbp->minus($release);
                @endphp
                <div class="callout mb-3">
                    <i class="bi bi-graph-down-arrow" aria-hidden="true"></i>
                    <div>
                        Approving {{ $release->isPositive() ? 'releases '.$release->format().' committed and ' : '' }}posts {{ $netGbp->format() }} actual on {{ $event['code'] ?? '' }}.
                        Forecast {{ $financials->forecast()->format(false) }} becomes {{ $financials->forecast()->plus($movement)->format(false) }};
                        margin moves from {{ $financials->margin()->format(false) }} ({{ $financials->marginPercent() }}%) to {{ $financials->margin()->minus($movement)->format(false) }} ({{ $financials->revenue->isZero() ? '—' : round(($financials->margin()->minor - $movement->minor) * 100 / $financials->revenue->minor, 1) }}%).
                    </div>
                </div>
            @endif
            @if ($open && ($canApprove || $canEdit))
                <label for="inv-note" class="form-label">{{ $isOver ? 'Why is the difference accepted? (or what is wrong, to dispute it)' : 'Note (needed to dispute)' }}</label>
                <textarea id="inv-note" rows="2" @class(['form-control', 'is-invalid' => $errors->has('note')]) wire:model="note" placeholder="For example: extra LED processor agreed on site by Hannah, change request raised"></textarea>
                @error('note')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            @endif
            @if (! $canApprove && $open)
                <p class="text-muted mb-0 mt-2">It is waiting for someone else to approve.</p>
            @endif
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            @if ($current && $canEdit && $current['status'] === 'disputed')
                <button type="button" class="btn btn-secondary" wire:click="reopen" wire:loading.attr="disabled">Reopen for approval</button>
            @endif
            @if ($current && $canEdit && $current['status'] === 'approved')
                <button type="button" class="btn btn-secondary" wire:click="markPaid" wire:loading.attr="disabled">Mark paid</button>
            @endif
            @if ($current && in_array($current['status'], ['received', 'matched'], true) && ($canApprove || $canEdit))
                <button type="button" class="btn btn-quiet text-negative" wire:click="dispute" wire:loading.attr="disabled">Dispute</button>
            @endif
            @if ($canApprove)
                <button type="button" class="btn btn-primary" wire:click="approve" wire:loading.attr="disabled">Approve for payment</button>
            @endif
        </x-slot:footer>
    </x-modal>

    @if ($canEdit)
        <x-modal id="record-modal" title="Record a supplier invoice" size="lg">
            <form id="record-form" class="row g-3" wire:submit="saveRecord" novalidate>
                <div class="col-12">
                    <label for="ri-po" class="form-label">Purchase order</label>
                    <select id="ri-po" @class(['form-select', 'is-invalid' => $errors->has('record.purchase_order_id')]) wire:model.live="record.purchase_order_id">
                        <option value="">No PO</option>
                        @foreach ($openPos as $p)
                            <option value="{{ $p['id'] }}">{{ $p['code'] }}, {{ $workspace->supplierName($p['supplier_id']) }}, {{ Money::of($p['subtotal_minor'], $p['currency'])->format() }}</option>
                        @endforeach
                    </select>
                    @error('record.purchase_order_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @if (($record['purchase_order_id'] ?? '') === '')
                    <div class="col-sm-6">
                        <label for="ri-sup" class="form-label">Supplier</label>
                        <select id="ri-sup" @class(['form-select', 'is-invalid' => $errors->has('record.supplier_id')]) wire:model="record.supplier_id">
                            <option value="">Choose…</option>
                            @foreach ($suppliersList as $sup)
                                <option value="{{ $sup['id'] }}">{{ $sup['name'] }}</option>
                            @endforeach
                        </select>
                        @error('record.supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="ri-event" class="form-label">Event</label>
                        <select id="ri-event" @class(['form-select', 'is-invalid' => $errors->has('record.event_id')]) wire:model.live="record.event_id">
                            <option value="">Choose…</option>
                            @foreach ($recordEvents as $e)
                                <option value="{{ $e['id'] }}">{{ $e['code'] }} {{ $e['name'] }}</option>
                            @endforeach
                        </select>
                        @error('record.event_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="ri-line" class="form-label">Budget line</label>
                        <select id="ri-line" @class(['form-select', 'is-invalid' => $errors->has('record.budget_line_id')]) wire:model="record.budget_line_id">
                            <option value="">Choose…</option>
                            @foreach ($recordLines as $l)
                                <option value="{{ $l['id'] }}">{{ $l['description'] }}</option>
                            @endforeach
                        </select>
                        @error('record.budget_line_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <x-form-field name="no_po_reason" model="record.no_po_reason" label="Why there is no PO" type="textarea" col="col-12" />
                @endif
                <x-form-field name="invoice_number" model="record.invoice_number" label="Invoice number" />
                <x-form-field name="invoice_date" model="record.invoice_date" label="Invoice date" type="date" col="col-sm-3" />
                <x-form-field name="due_date" model="record.due_date" label="Due" type="date" col="col-sm-3" />
                <x-form-field name="net" model="record.net" label="Net" col="col-sm-4" inputmode="decimal" />
                <div class="col-sm-4">
                    <label for="ri-cur" class="form-label">Currency</label>
                    <select id="ri-cur" @class(['form-select', 'is-invalid' => $errors->has('record.currency')]) wire:model="record.currency">
                        @foreach (config('hive.finance.currencies') as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                    @error('record.currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-4">
                    <label for="ri-vat" class="form-label">VAT</label>
                    <select id="ri-vat" class="form-select" wire:model="record.vat_rate">
                        @foreach ($vatRates as $r)
                            <option value="{{ $r->value }}">{{ $r->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label for="ri-file" class="form-label">Invoice file (optional)</label>
                    <input id="ri-file" type="file" accept=".pdf,.jpg,.jpeg,.png" @class(['form-control', 'is-invalid' => $errors->has('upload')]) wire:model="upload">
                    @error('upload')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="record-form" class="btn btn-primary" wire:loading.attr="disabled">Record invoice</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
