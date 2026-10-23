<div>
    <x-page-head :title="$clientId ? 'Edit '.$form['name'] : 'Add a client'">
        <x-slot:crumbs><a href="{{ route('clients') }}" wire:navigate>Clients</a></x-slot:crumbs>
    </x-page-head>
    <form wire:submit="save" class="d-grid gap-3 mw-form" novalidate>
        <section class="card">
            <div class="card-header"><h2 class="card-title">Client</h2></div>
            <div class="card-body row g-3">
                <x-form-field name="name" label="Company name" />
                <x-form-field name="sector" label="Sector" />
                <x-form-field name="billing_address" label="Billing address" col="col-12" />
                <x-form-field name="vat_number" label="VAT number (optional)" />
                <x-form-field name="payment_terms_days" label="Payment terms (days)" type="number" min="0" />
                <div class="col-sm-6">
                    <label for="c-lead" class="form-label">Account lead</label>
                    <select id="c-lead" class="form-select" wire:model="form.account_lead_id">
                        <option value="">None</option>
                        @foreach ($leads as $l)
                            <option value="{{ $l->id }}">{{ $l->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
                <x-form-field name="notes" label="Notes (optional)" type="textarea" col="col-12" />
            </div>
        </section>
        @include('livewire.directory.partials.contacts')
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $clientId ? 'Save changes' : 'Add client' }}</button>
            <a href="{{ route('clients') }}" class="btn btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
