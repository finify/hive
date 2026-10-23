@php
    use App\Enums\PurchaseOrderStatus;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-page-head title="Purchase orders" :answer="$pos->total().' purchase orders. Approved POs count as committed spend until their invoice is approved.'" />

    <div class="filters">
        <label class="visually-hidden" for="po-status">Status</label>
        <select id="po-status" class="form-select" wire:model.live="status">
            <option value="">Any status</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="table-wrap">
        @if ($pos->isEmpty())
            <x-empty title="No purchase orders match">POs are raised against an event’s budget line and a supplier. Approval limits decide who signs them off.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>PO</th><th>Event</th><th>Supplier</th><th>Line</th><th class="num">Net</th><th class="num">GBP net</th><th>Status</th><th>Approved by</th></tr></thead>
                <tbody>
                    @foreach ($pos as $po)
                        @php($net = Money::of($po['subtotal_minor'], $po['currency']))
                        <tr>
                            <td class="code">{{ $po['code'] }}</td>
                            <td><x-event-code :event="$workspace->event($po['event_id'])" /></td>
                            <td>{{ $workspace->suppliers()->get($po['supplier_id'])['name'] }}</td>
                            <td>{{ $po['lines'][0]['description'] }}</td>
                            <td class="num">{{ $net->format() }}</td>
                            <td class="num">{{ $po['currency'] === 'GBP' ? $net->format() : $net->convert((string) $po['fx_rate'], 'GBP')->format() }}</td>
                            <td><x-status :value="PurchaseOrderStatus::from($po['status'])" /></td>
                            <td>{{ $po['approved_by'] ? $workspace->personName($po['approved_by']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-3">{{ $pos->links() }}</div>
</div>
