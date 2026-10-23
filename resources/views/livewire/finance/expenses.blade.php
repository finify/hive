@php
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-page-head title="Expenses" :answer="$expenses->count().' expense claims you can see. Approved expenses become actual costs on their event.'" />

    <div class="table-wrap">
        @if ($expenses->isEmpty())
            <x-empty title="No expenses yet">Claims with receipts appear here: travel, subsistence, taxis, parking, materials and mileage. Producers approve first, then finance.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Date</th><th>Person</th><th>Event</th><th>Category</th><th>Description</th><th class="num">Amount</th><th class="num">VAT</th><th>Receipt</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($expenses as $x)
                        <tr>
                            <td class="tnum text-nowrap">{{ Display::date($x['date']) }}</td>
                            <td>{{ $workspace->personName($x['person_id']) }}</td>
                            <td><x-event-code :event="$workspace->event($x['event_id'])" /></td>
                            <td>{{ ucfirst($x['category']) }}</td>
                            <td>{{ $x['description'] }}</td>
                            <td class="num">{{ Money::of($x['amount_minor'], $x['currency'])->format() }}</td>
                            <td class="num">{{ Money::of($x['vat_minor'], $x['currency'])->format() }}</td>
                            <td>{{ $x['receipt'] ? 'Attached' : ($x['mileage_miles'] ? $x['mileage_miles'].' miles' : 'None') }}</td>
                            <td><x-pill :tone="$x['status'] === 'approved' ? 'positive' : 'warning'">{{ ucfirst($x['status']) }}</x-pill></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
