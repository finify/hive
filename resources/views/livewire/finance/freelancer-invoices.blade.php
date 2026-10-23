@php
    use App\Enums\FreelancerInvoiceStatus;
    use App\Support\DateRange;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $waiting = $invoices->where('status', FreelancerInvoiceStatus::Received)->count();
@endphp
<div>
    <x-page-head title="Freelancer invoices" :answer="$isFinance ? $waiting.' waiting for approval. Each total is checked against approved time at the rate in force each day; crew cost was posted when the time was approved.' : 'Send an invoice for your approved time. Hive checks it against your approved timesheets.'" />

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header"><h2 class="card-title">Invoices</h2></div>
            @if ($invoices->isEmpty())
                <x-empty title="No invoices yet">Invoices for approved time appear here, with how each compares with the time approved.</x-empty>
            @else
                <div class="table-wrap border-0 rounded-0">
                    <table class="table">
                        <thead><tr><th>Invoice</th><th>From</th><th>Period</th><th class="num">Net</th><th class="num">Approved time</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($invoices as $i)
                                @php($diff = $i->difference())
                                <tr wire:key="fi-{{ $i->id }}">
                                    <td><span class="code">{{ $i->invoice_number }}</span>@if ($i->is_self_billed)<span class="d-block small text-muted">Self-billed</span>@endif</td>
                                    <td>{{ $workspace->personName($i->person_id) }}</td>
                                    <td class="tnum text-nowrap">{{ DateRange::of($i->period_start->toDateString(), $i->period_end->toDateString())->format() }}</td>
                                    <td class="num">{{ Money::of($i->net_minor)->format() }}@if ($i->vat_minor)<span class="d-block small text-muted">+ {{ Money::of($i->vat_minor)->format() }} VAT</span>@endif</td>
                                    <td @class(['num', 'text-negative' => $diff !== 0])>{{ Money::of($i->expected_minor)->format() }}@if ($diff !== 0)<span class="d-block small">{{ $diff > 0 ? Money::of($diff)->format().' over' : Money::of(-$diff)->format().' under' }}</span>@endif</td>
                                    <td><x-status :value="$i->status" />@if ($i->notes)<span class="d-block small text-muted">{{ $i->notes }}</span>@endif</td>
                                    <td class="text-end text-nowrap">
                                        @if (in_array($i->id, $decidable, true))
                                            <button type="button" class="btn btn-secondary btn-sm" wire:click="startDecision({{ $i->id }})">Review</button>
                                        @elseif ($isFinance && $i->status === FreelancerInvoiceStatus::Approved)
                                            <button type="button" class="btn btn-quiet btn-sm" wire:click="markPaid({{ $i->id }})">Mark paid</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="span-4 d-grid gap-3 align-content-start">
            @if ($mine)
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Send an invoice</h2></div>
                    <form class="card-body row g-2" wire:submit="submitInvoice" novalidate>
                        <x-form-field name="period_start" model="invoiceForm.period_start" label="From" type="date" col="col-6" live />
                        <x-form-field name="period_end" model="invoiceForm.period_end" label="To" type="date" col="col-6" live />
                        @if ($expectedMine)
                            <div class="col-12 small text-muted">Your approved time in that period comes to {{ $expectedMine->format() }}.</div>
                        @endif
                        <x-form-field name="invoice_number" model="invoiceForm.invoice_number" label="Your invoice number" col="col-12" />
                        <x-form-field name="net" model="invoiceForm.net" label="Net" col="col-6" inputmode="decimal" />
                        <x-form-field name="vat" model="invoiceForm.vat" label="VAT (if registered)" col="col-6" inputmode="decimal" />
                        <div class="col-12">
                            <label for="fi-file" class="form-label">Invoice</label>
                            <input id="fi-file" type="file" accept=".pdf,.jpg,.jpeg,.png" @class(['form-control', 'is-invalid' => $errors->has('upload')]) wire:model="upload">
                            @error('upload')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12"><button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">Send invoice</button></div>
                    </form>
                </section>
            @endif

            @if ($selfBilling && $selfBillPeople->isNotEmpty())
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Self-billing</h2></div>
                    <form class="card-body row g-2" wire:submit="raiseSelfBill" novalidate>
                        @if ($isFinance)
                            <div class="col-12">
                                <label for="sb-person" class="form-label">Freelancer</label>
                                <select id="sb-person" @class(['form-select', 'is-invalid' => $errors->has('selfBill.person_id')]) wire:model.live="selfBill.person_id">
                                    <option value="">Choose…</option>
                                    @foreach ($selfBillPeople as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <x-form-field name="period_start" model="selfBill.period_start" label="From" type="date" col="col-6" live />
                        <x-form-field name="period_end" model="selfBill.period_end" label="To" type="date" col="col-6" live />
                        @if ($selfBillPreview)
                            <div class="col-12 small text-muted">Approved time in the period: {{ $selfBillPreview->format() }}.</div>
                        @endif
                        <div class="col-12"><button type="submit" class="btn btn-secondary w-100" wire:loading.attr="disabled">Raise self-billed invoice</button></div>
                        <div class="col-12 small text-muted">Only for freelancers with a current self-billing agreement on their profile. VAT is added if they are VAT registered.</div>
                    </form>
                </section>
            @endif
        </div>
    </div>

    <x-modal id="fi-modal" :title="$deciding ? 'Invoice '.$deciding->invoice_number : 'Invoice'">
        @if ($deciding)
            <dl class="kv mb-3">
                <dt>From</dt><dd>{{ $workspace->personName($deciding->person_id) }}</dd>
                <dt>Period</dt><dd>{{ DateRange::of($deciding->period_start->toDateString(), $deciding->period_end->toDateString())->format() }}</dd>
                <dt>Net</dt><dd class="tnum">{{ Money::of($deciding->net_minor)->format() }}</dd>
                <dt>Approved time</dt><dd class="tnum">{{ Money::of($deciding->expected_minor)->format() }}</dd>
            </dl>
            @if ($deciding->difference() !== 0)
                <div class="callout is-warning mb-3"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>This differs from approved time by {{ Money::of(abs($deciding->difference()))->format() }}. Record why if you approve it.</div></div>
            @endif
            <label for="fi-note" class="form-label">Note</label>
            <textarea id="fi-note" rows="2" @class(['form-control', 'is-invalid' => $errors->has('note')]) wire:model="note"></textarea>
            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-quiet" wire:click="decide(false)" wire:loading.attr="disabled">Query it</button>
            <button type="button" class="btn btn-primary" wire:click="decide(true)" wire:loading.attr="disabled">Approve for payment</button>
        </x-slot:footer>
    </x-modal>
</div>
