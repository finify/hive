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
                        @php($isOver = $workspace->invoiceOverTolerance($si))
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
        @if ($current && $po)
            @php($isOver = $workspace->invoiceOverTolerance($current))
            @php($event = $workspace->event($current['event_id']))
            @php($net = Money::of($current['net_minor'], $current['currency']))
            @php($poNet = Money::of($po['subtotal_minor'], $po['currency']))
            <dl class="kv mb-3">
                <dt>Supplier</dt><dd>{{ $workspace->suppliers()->get($current['supplier_id'])['name'] }}</dd>
                <dt>Event</dt><dd><span class="code">{{ $event['code'] }}</span> {{ $event['name'] }}</dd>
                <dt>Purchase order</dt><dd><span class="code">{{ $po['code'] }}</span> {{ $po['lines'][0]['description'] }}</dd>
                <dt>Goods received</dt><dd>{{ $po['goods_received'] ? 'Confirmed' : 'Not confirmed yet' }}</dd>
            </dl>
            <div class="table-wrap mb-3">
                <table class="table">
                    <tbody>
                        <tr><td>PO net</td><td class="num">{{ $poNet->format() }}</td></tr>
                        <tr><td>Invoice net</td><td class="num">{{ $net->format() }}</td></tr>
                        <tr class="fw-medium"><td>Difference</td><td @class(['num', 'text-negative' => $isOver])>{{ $net->minus($poNet)->format() }}</td></tr>
                        <tr><td class="text-muted">Tolerance (5% or £250, whichever is lower)</td><td class="num text-muted">{{ $allowance->format() }}</td></tr>
                    </tbody>
                </table>
            </div>
            @if ($current['notes'])
                <p class="note small">{{ $current['notes'] }}</p>
            @endif
            @if ($financials)
                <div class="callout mb-3">
                    <i class="bi bi-graph-down-arrow" aria-hidden="true"></i>
                    <div>
                        Approving releases {{ $poNet->format() }} committed and posts {{ $net->format() }} actual on {{ $event['code'] }}.
                        Forecast {{ $financials->forecast()->format(false) }} becomes {{ $financials->forecast()->plus($net->minus($poNet))->format(false) }};
                        margin moves from {{ $financials->marginPercent() }}% to {{ $financials->revenue->isZero() ? '—' : round(($financials->margin()->minor - ($net->minor - $poNet->minor)) * 100 / $financials->revenue->minor, 1) }}%.
                    </div>
                </div>
            @endif
            @if ($canApprove && $isOver)
                <label for="inv-note" class="form-label">Why is the difference accepted?</label>
                <textarea id="inv-note" rows="2" @class(['form-control', 'is-invalid' => $errors->has('note')]) wire:model="note" placeholder="For example: extra LED processor agreed on site by Hannah, change request raised"></textarea>
                @error('note')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            @endif
            @if (! $canApprove && ! in_array($current['status'], ['approved', 'paid'], true))
                <p class="text-muted mb-0">You can view this invoice but not approve it.</p>
            @endif
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            @if ($canApprove)
                <button type="button" class="btn btn-primary" wire:click="approve" wire:loading.attr="disabled">Approve for payment</button>
            @endif
        </x-slot:footer>
    </x-modal>
</div>
