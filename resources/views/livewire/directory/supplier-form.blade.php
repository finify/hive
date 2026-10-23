<div>
    <x-page-head :title="$supplierId ? 'Edit '.$form['name'] : 'Add a supplier'">
        <x-slot:crumbs><a href="{{ route('suppliers') }}" wire:navigate>Suppliers</a></x-slot:crumbs>
    </x-page-head>
    <form wire:submit="save" class="d-grid gap-3 mw-form" novalidate>
        <section class="card">
            <div class="card-header"><h2 class="card-title">Supplier</h2></div>
            <div class="card-body row g-3">
                <x-form-field name="name" label="Company name" />
                <div class="col-sm-6">
                    <label for="s-cat" class="form-label">Category</label>
                    <select id="s-cat" class="form-select" wire:model="form.category">
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <x-form-field name="vat_number" label="VAT number (optional)" />
                <x-form-field name="payment_terms_days" label="Payment terms (days)" type="number" min="0" />
                <x-form-field name="insurance_expires_at" label="Insurance expires" type="date" hint="A new date records a new insurance document." />
                <x-form-field name="rating" label="Rating, 1 to 5 (optional)" type="number" min="1" max="5" step="0.1" />
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="s-approved" wire:model="form.is_approved">
                        <label class="form-check-label" for="s-approved">Approved supplier</label>
                    </div>
                </div>
                <x-form-field name="notes" label="Notes (optional)" type="textarea" col="col-12" />
            </div>
        </section>
        @include('livewire.directory.partials.contacts')
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $supplierId ? 'Save changes' : 'Add supplier' }}</button>
            <a href="{{ route('suppliers') }}" class="btn btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
