@php
    use App\Enums\BillingStatus;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $max = max(1, collect($months)->max(fn ($m) => $m->minor));
@endphp
<div>
    <x-page-head title="Client billing" :answer="$overdue->isNotEmpty() ? $overdue->count().' payment overdue: '.$overdue->map(fn ($m) => $workspace->event($m['event_id'])['code'].' '.Money::of($m['amount_minor'])->format(false))->implode(', ').'. Invoices are raised in the accounting system; Hive tracks the milestones.' : 'No payments overdue.'" />

    <section class="card mb-3">
        <div class="card-header"><h2 class="card-title">Expected inflows by month</h2><span class="small text-muted ms-auto">Unpaid milestones by due month</span></div>
        <div class="card-body">
            <ul class="list-unstyled m-0 d-grid gap-2">
                @foreach ($months as $label => $amount)
                    <li class="d-grid align-items-center gap-3" style="grid-template-columns: 80px 1fr 110px">
                        <span class="small">{{ $label }}</span>
                        <div class="meter"><span style="width: {{ round($amount->minor * 100 / $max) }}%"></span></div>
                        <span class="tnum text-end">{{ $amount->format(false) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Event</th><th>Milestone</th><th class="num">Share</th><th class="num">Amount</th><th>Due</th><th>Invoice</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($milestones as $m)
                    <tr>
                        <td><x-event-code :event="$workspace->event($m['event_id'])" /> <span class="d-block small text-muted">{{ $workspace->event($m['event_id'])['name'] }}</span></td>
                        <td>{{ $m['description'] }}</td>
                        <td class="num">{{ $m['percentage'] }}%</td>
                        <td class="num">{{ Money::of($m['amount_minor'])->format(false) }}</td>
                        <td class="tnum">{{ Display::date($m['due_date']) }}</td>
                        <td class="code">{{ $m['invoice_reference'] ?? '—' }}</td>
                        <td><x-status :value="BillingStatus::from($m['status'])" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
