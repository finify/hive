@php
    use App\Support\Money;
    $err = fn (string $key) => $errors->first($key);
@endphp
<div>
    <x-page-head :title="$poId ? 'Edit purchase order' : 'Raise a purchase order'" :answer="'Producers approve up to '.Money::of($limits['producer'])->format(false).' on their events, the operations director up to '.Money::of($limits['operations-director'])->format(false).'; above that it needs two signatures. Nobody approves their own PO.'">
        <x-slot:crumbs><a href="{{ route('purchase-orders') }}" wire:navigate>Purchase orders</a></x-slot:crumbs>
    </x-page-head>

    <form wire:submit="save" class="d-grid gap-3 mw-form" novalidate>
        <section class="card">
            <div class="card-header"><h2 class="card-title">What it’s for</h2></div>
            <div class="card-body row g-3">
                <div class="col-sm-6">
                    <label for="po-event" class="form-label">Event</label>
                    <select id="po-event" @class(['form-select', 'is-invalid' => $err('event_id')]) wire:model.live="eventId">
                        <option value="">Choose…</option>
                        @foreach ($events as $e)
                            <option value="{{ $e['id'] }}">{{ $e['code'] }} {{ $e['name'] }}</option>
                        @endforeach
                    </select>
                    @if ($err('event_id'))<div class="invalid-feedback">{{ $err('event_id') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="po-line" class="form-label">Budget line</label>
                    <select id="po-line" @class(['form-select', 'is-invalid' => $err('budget_line_id')]) wire:model="budgetLineId" @disabled($eventId === '')>
                        <option value="">Choose…</option>
                        @foreach ($budgetLines as $line)
                            <option value="{{ $line['id'] }}">{{ $line['description'] }} ({{ $line['remaining']->format(false) }} left to commit)</option>
                        @endforeach
                    </select>
                    @if ($err('budget_line_id'))<div class="invalid-feedback">{{ $err('budget_line_id') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="po-supplier" class="form-label">Supplier</label>
                    <select id="po-supplier" @class(['form-select', 'is-invalid' => $err('supplier_id')]) wire:model="supplierId">
                        <option value="">Choose…</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}{{ $s->is_approved ? '' : ' (not yet approved)' }}</option>
                        @endforeach
                    </select>
                    @if ($err('supplier_id'))<div class="invalid-feedback">{{ $err('supplier_id') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="po-currency" class="form-label">Currency</label>
                    <select id="po-currency" class="form-select" wire:model.live="currency">
                        @foreach ($currencies as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Lines</h2>
                <button type="button" class="btn btn-secondary btn-sm ms-auto" wire:click="addLine">Add a line</button>
            </div>
            <div class="card-body">
                @if ($err('lines'))<div class="small text-negative mb-2">{{ $err('lines') }}</div>@endif
                @foreach ($lines as $i => $line)
                    <div class="row g-2 align-items-end mb-3" wire:key="pol-{{ $i }}">
                        <div class="col-12 col-md-5">
                            <label for="pol-d-{{ $i }}" class="form-label small">Description</label>
                            <input id="pol-d-{{ $i }}" @class(['form-control', 'is-invalid' => $err('lines.'.$i.'.description')]) wire:model="lines.{{ $i }}.description">
                            @if ($err('lines.'.$i.'.description'))<div class="invalid-feedback">{{ $err('lines.'.$i.'.description') }}</div>@endif
                        </div>
                        <div class="col-4 col-md-2">
                            <label for="pol-q-{{ $i }}" class="form-label small">Quantity</label>
                            <input id="pol-q-{{ $i }}" inputmode="decimal" @class(['form-control tnum', 'is-invalid' => $err('lines.'.$i.'.quantity')]) wire:model.blur="lines.{{ $i }}.quantity">
                        </div>
                        <div class="col-8 col-md-2">
                            <label for="pol-p-{{ $i }}" class="form-label small">Unit price</label>
                            <input id="pol-p-{{ $i }}" inputmode="decimal" @class(['form-control tnum', 'is-invalid' => $err('lines.'.$i.'.unit_price')]) wire:model.blur="lines.{{ $i }}.unit_price" placeholder="0.00">
                            @if ($err('lines.'.$i.'.unit_price'))<div class="invalid-feedback">{{ $err('lines.'.$i.'.unit_price') }}</div>@endif
                        </div>
                        <div class="col-8 col-md-2">
                            <label for="pol-v-{{ $i }}" class="form-label small">VAT</label>
                            <select id="pol-v-{{ $i }}" class="form-select" wire:model="lines.{{ $i }}.vat_rate">
                                @foreach ($vatRates as $rate)
                                    <option value="{{ $rate->value }}">{{ $rate->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-4 col-md-1">
                            @if (count($lines) > 1)
                                <button type="button" class="btn btn-quiet w-100" wire:click="removeLine({{ $i }})" aria-label="Remove line {{ $i + 1 }}">Remove</button>
                            @endif
                        </div>
                    </div>
                @endforeach
                <div class="d-flex justify-content-end gap-3 border-top border-rule pt-2">
                    <span class="text-muted">Net</span><span class="tnum fw-medium">{{ $subtotal->format() }}</span>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-body">
                <label for="po-notes" class="form-label">Notes for the supplier (optional)</label>
                <textarea id="po-notes" rows="2" class="form-control" wire:model="notes" placeholder="For example: deliver to Loading bay 2 by 07:00 on 9 Nov"></textarea>
            </div>
        </section>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $poId ? 'Save draft' : 'Raise as draft' }}</button>
            <a href="{{ route('purchase-orders') }}" class="btn btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
