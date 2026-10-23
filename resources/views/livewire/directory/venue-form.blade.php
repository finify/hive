<div>
    <x-page-head :title="$venueId ? 'Edit '.$form['name'] : 'Add a venue'">
        <x-slot:crumbs><a href="{{ route('venues') }}" wire:navigate>Venues</a></x-slot:crumbs>
    </x-page-head>
    <form wire:submit="save" class="d-grid gap-3 mw-form" novalidate>
        <section class="card">
            <div class="card-header"><h2 class="card-title">Venue</h2></div>
            <div class="card-body row g-3">
                <x-form-field name="name" label="Name" />
                <x-form-field name="capacity" label="Capacity" type="number" min="1" />
                <x-form-field name="address" label="Address" col="col-12" />
                <x-form-field name="city" label="City" />
                <x-form-field name="country" label="Country" />
                <x-form-field name="access_notes" label="Loading and access" type="textarea" col="col-12" />
                <x-form-field name="power_notes" label="Power and rigging" type="textarea" col="col-12" />
            </div>
        </section>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $venueId ? 'Save changes' : 'Add venue' }}</button>
            <a href="{{ route('venues') }}" class="btn btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
