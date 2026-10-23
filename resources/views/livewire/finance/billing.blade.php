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
            <thead><tr><th>Event</th><th>Milestone</th><th class="num">Share</th><th class="num">Amount</th><th>Due</th><th>Invoice</th><th>Status</th>@if ($canManage)<th><span class="visually-hidden">Actions</span></th>@endif</tr></thead>
            <tbody>
                @foreach ($milestones as $m)
                    <tr>
                        <td wire:key="ms-{{ $m['id'] }}"><x-event-code :event="$workspace->event($m['event_id'])" /> <span class="d-block small text-muted">{{ $workspace->event($m['event_id'])['name'] }}</span></td>
                        <td>{{ $m['description'] }}</td>
                        <td class="num">{{ $m['percentage'] ? $m['percentage'].'%' : '—' }}</td>
                        <td class="num">{{ Money::of($m['amount_minor'])->format(false) }}</td>
                        <td class="tnum">{{ Display::date($m['due_date']) }}</td>
                        <td class="code">{{ $m['invoice_reference'] ?? '—' }}</td>
                        <td><x-status :value="BillingStatus::from($m['status'])" /></td>
                        @if ($canManage)
                            <td class="text-end text-nowrap">
                                @if (in_array($m['status'], ['planned', 'due'], true))
                                    <button type="button" class="btn btn-quiet btn-sm" wire:click="startInvoice({{ $m['id'] }})">Invoiced</button>
                                @elseif (in_array($m['status'], ['invoiced', 'overdue'], true))
                                    <button type="button" class="btn btn-quiet btn-sm" wire:click="markPaid({{ $m['id'] }})" wire:confirm="Record this payment as received today?">Paid</button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($canManage)
        <section class="card mt-3 mw-form">
            <div class="card-header"><h2 class="card-title">Add a milestone</h2></div>
            <form class="card-body row g-2" wire:submit="addMilestone" novalidate>
                <div class="col-sm-6">
                    <label for="ms-event" class="form-label">Event</label>
                    <select id="ms-event" @class(['form-select', 'is-invalid' => $errors->has('milestone.event_id')]) wire:model="milestone.event_id">
                        <option value="">Choose…</option>
                        @foreach ($billableEvents as $e)
                            <option value="{{ $e['id'] }}">{{ $e['code'] }} {{ $e['name'] }}</option>
                        @endforeach
                    </select>
                    @error('milestone.event_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <x-form-field name="description" model="milestone.description" label="Milestone" placeholder="For example Balance on reconciliation" />
                <x-form-field name="percentage" model="milestone.percentage" label="Share of revenue (%)" col="col-sm-4" inputmode="numeric" />
                <x-form-field name="amount" model="milestone.amount" label="Or an amount" col="col-sm-4" inputmode="decimal" />
                <x-form-field name="due_date" model="milestone.due_date" label="Due" type="date" col="col-sm-4" />
                <div class="col-12"><button type="submit" class="btn btn-secondary" wire:loading.attr="disabled">Add milestone</button></div>
            </form>
        </section>

        <x-modal id="invoice-ref-modal" title="Record the invoice">
            <label for="ms-ref" class="form-label">Invoice number in the accounting system</label>
            <input id="ms-ref" @class(['form-control', 'is-invalid' => $errors->has('reference')]) wire:model="reference" placeholder="For example INV-3121">
            @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <x-slot:footer>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" wire:click="markInvoiced" wire:loading.attr="disabled">Save</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
