@php
    use App\Enums\ExpenseCategory;
    use App\Enums\ExpenseStatus;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $isMileage = $claim['category'] === 'mileage';
@endphp
<div>
    <x-page-head title="Expenses" :answer="$expenses->count().' expense claims you can see'.(count($waitingForMe) ? ', '.count($waitingForMe).' waiting for you' : '').'. Approved expenses become actual costs on their event.'" />

    <div class="grid-12">
        <div @class(['table-wrap', 'span-8' => $canClaim, 'span-12' => ! $canClaim])>
            @if ($expenses->isEmpty())
                <x-empty title="No expenses yet">Claims with receipts appear here: travel, subsistence, taxis, parking, materials and mileage. Producers approve first, then finance.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>Date</th><th>Person</th><th>Event</th><th>What</th><th class="num">Amount</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($expenses as $x)
                            <tr wire:key="ex-{{ $x['id'] }}">
                                <td class="tnum text-nowrap">{{ Display::date($x['date']) }}</td>
                                <td>{{ $workspace->personName($x['person_id']) }}</td>
                                <td>@if ($x['event_id'])<x-event-code :event="$workspace->event($x['event_id'])" />@else<span class="text-muted">—</span>@endif</td>
                                <td>
                                    {{ ExpenseCategory::from($x['category'])->label() }}: {{ $x['description'] }}
                                    <span class="d-block small text-muted">{{ $x['mileage_miles'] ? $x['mileage_miles'].' miles' : ($x['receipt'] ? 'Receipt '.$x['receipt'] : 'No receipt') }}</span>
                                    @if ($x['decision_comment'])<span class="d-block small text-muted">“{{ $x['decision_comment'] }}”</span>@endif
                                </td>
                                <td class="num">{{ Money::of($x['amount_minor'], $x['currency'])->format() }}@if ($x['currency'] !== 'GBP')<span class="d-block small text-muted">{{ Money::of($x['amount_gbp_minor'])->format() }}</span>@endif @if ($x['vat_minor'])<span class="d-block small text-muted">incl. {{ Money::of($x['vat_minor'], $x['currency'])->format() }} VAT</span>@endif</td>
                                <td><x-status :value="ExpenseStatus::from($x['status'])" /></td>
                                <td class="text-end">
                                    @if (in_array($x['id'], $waitingForMe, true))
                                        <button type="button" class="btn btn-secondary btn-sm" wire:click="startDecision({{ $x['id'] }})">Review</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($canClaim)
            <section class="card span-4">
                <div class="card-header"><h2 class="card-title">Claim an expense</h2></div>
                <form class="card-body row g-2" wire:submit="submit" novalidate>
                    <div class="col-12">
                        <label for="ex-event" class="form-label">Event</label>
                        <select id="ex-event" @class(['form-select', 'is-invalid' => $errors->has('claim.event_id')]) wire:model="claim.event_id">
                            <option value="">Not for an event</option>
                            @foreach ($myEvents as $e)
                                <option value="{{ $e['id'] }}">{{ $e['code'] }} {{ $e['name'] }}</option>
                            @endforeach
                        </select>
                        @error('claim.event_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label for="ex-cat" class="form-label">Category</label>
                        <select id="ex-cat" class="form-select" wire:model.live="claim.category">
                            @foreach ($categories as $c)
                                <option value="{{ $c->value }}">{{ $c->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-form-field name="date" model="claim.date" label="Date" type="date" col="col-6" />
                    <x-form-field name="description" model="claim.description" label="What for" col="col-12" placeholder="For example Taxi home after 23:30 wrap" />
                    @if ($isMileage)
                        <x-form-field name="miles" model="claim.miles" label="Miles" col="col-12" inputmode="decimal" :hint="'Paid at '.$mileageRate->format().' a mile.'" />
                    @else
                        <x-form-field name="amount" model="claim.amount" label="Amount" col="col-5" inputmode="decimal" />
                        <div class="col-3">
                            <label for="ex-cur" class="form-label">Currency</label>
                            <select id="ex-cur" class="form-select" wire:model="claim.currency">
                                @foreach ($currencies as $c)
                                    <option value="{{ $c }}">{{ $c }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-form-field name="vat" model="claim.vat" label="VAT in it" col="col-4" inputmode="decimal" />
                        <div class="col-12">
                            <label for="ex-receipt" class="form-label">Receipt</label>
                            <input id="ex-receipt" type="file" accept=".pdf,.jpg,.jpeg,.png,image/*" capture="environment" @class(['form-control', 'is-invalid' => $errors->has('receipt')]) wire:model="receipt">
                            @error('receipt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div wire:loading wire:target="receipt" class="small text-muted mt-1">Uploading…</div>
                        </div>
                    @endif
                    <div class="col-12"><button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">Send claim</button></div>
                </form>
            </section>
        @endif
    </div>

    <x-modal id="expense-modal" title="Review expense">
        @if ($deciding)
            <dl class="kv mb-3">
                <dt>From</dt><dd>{{ $workspace->personName($deciding['person_id']) }}</dd>
                <dt>For</dt><dd>{{ ExpenseCategory::from($deciding['category'])->label() }}: {{ $deciding['description'] }}</dd>
                <dt>Amount</dt><dd class="tnum">{{ Money::of($deciding['amount_minor'], $deciding['currency'])->format() }}</dd>
                <dt>Event</dt><dd>{{ $deciding['event_id'] ? ($workspace->event($deciding['event_id'])['code'] ?? '') : '—' }}</dd>
            </dl>
            <label for="ex-comment" class="form-label">Comment (needed to reject)</label>
            <textarea id="ex-comment" rows="2" @class(['form-control', 'is-invalid' => $errors->has('comment')]) wire:model="comment"></textarea>
            @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-quiet" wire:click="decide(false)" wire:loading.attr="disabled">Reject</button>
            <button type="button" class="btn btn-primary" wire:click="decide(true)" wire:loading.attr="disabled">Approve</button>
        </x-slot:footer>
    </x-modal>
</div>
