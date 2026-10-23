@php
    use App\Support\Display;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-page-head title="Exports" answer="CSV files for the accounting system and payment runs. Each export is recorded in the audit log." />

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header"><h2 class="card-title">Download</h2></div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-6 col-sm-4">
                        <label for="ex-from" class="form-label">From</label>
                        <input id="ex-from" type="date" class="form-control" wire:model.live="from">
                    </div>
                    <div class="col-6 col-sm-4">
                        <label for="ex-to" class="form-label">To</label>
                        <input id="ex-to" type="date" class="form-control" wire:model.live="to">
                    </div>
                </div>
                @unless ($valid)
                    <p class="small text-negative">Choose a start on or before the end.</p>
                @endunless
            </div>
            <ul class="list-rows">
                @foreach ($types as $type => $label)
                    <li>
                        <div class="row-main"><div class="row-title">{{ $label }}</div><div class="row-sub">{{ match ($type) { 'supplier-invoices' => 'By invoice date, with nominal codes and sterling amounts.', 'freelancer-costs' => 'Approved event time by date, with the day rate in force. Contains rates.', 'expenses' => 'Approved claims by date of spend.', default => 'Milestones by due date, with invoice references.' } }}</div></div>
                        @if ($valid)
                            <a href="{{ route('exports.download', ['type' => $type, 'from' => $from, 'to' => $to]) }}" class="btn btn-secondary btn-sm"><i class="bi bi-download me-1" aria-hidden="true"></i>CSV</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card span-4">
            <div class="card-header"><h2 class="card-title">Exchange rates</h2></div>
            <ul class="list-rows">
                @foreach ($rates as $r)
                    <li>
                        <div class="row-main"><div class="row-title tnum">1 {{ $r->currency }} = £{{ $r->rate_to_gbp }}</div><div class="row-sub">From {{ Display::date($r->effective_on) }}, set by {{ $workspace->personName($r->set_by) }}</div></div>
                    </li>
                @endforeach
            </ul>
            @if ($canManage)
                <form class="card-body row g-2 border-top border-rule" wire:submit="setRate" novalidate>
                    <div class="col-5">
                        <label for="fx-cur" class="form-label">Currency</label>
                        <select id="fx-cur" class="form-select" wire:model="rate.currency">
                            @foreach ($currencies as $c)
                                <option value="{{ $c }}">{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-form-field name="rate_to_gbp" model="rate.rate_to_gbp" label="In pounds" col="col-7" inputmode="decimal" placeholder="0.8650" />
                    <x-form-field name="effective_on" model="rate.effective_on" label="From" type="date" col="col-12" />
                    <div class="col-12"><button type="submit" class="btn btn-secondary w-100" wire:loading.attr="disabled">Save rate</button></div>
                    <div class="col-12 small text-muted">Costs already posted keep the rate they were posted at.</div>
                </form>
            @endif
        </section>
    </div>
</div>
