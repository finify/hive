<section class="card">
    <div class="card-header">
        <h2 class="card-title">Contacts</h2>
        <button type="button" class="btn btn-secondary btn-sm ms-auto" wire:click="addContact">Add a contact</button>
    </div>
    <div class="card-body d-grid gap-3">
        @forelse ($contacts as $i => $c)
            <div class="row g-2 align-items-end" wire:key="contact-{{ $i }}">
                <x-form-field :name="'n'.$i" label="Name" :model="'contacts.'.$i.'.name'" col="col-sm-3" />
                <x-form-field :name="'r'.$i" label="Role" :model="'contacts.'.$i.'.role'" col="col-sm-3" />
                <x-form-field :name="'e'.$i" label="Email" type="email" :model="'contacts.'.$i.'.email'" col="col-sm-3" />
                <x-form-field :name="'p'.$i" label="Phone" type="tel" :model="'contacts.'.$i.'.phone'" col="col-sm-3" />
                <div class="col-12 d-flex gap-3 align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="primary-{{ $i }}" wire:model="contacts.{{ $i }}.is_primary">
                        <label class="form-check-label small" for="primary-{{ $i }}">Primary contact</label>
                    </div>
                    <button type="button" class="btn btn-quiet btn-sm" wire:click="removeContact({{ $i }})">Remove</button>
                </div>
            </div>
        @empty
            <p class="text-muted mb-0">No contacts yet.</p>
        @endforelse
    </div>
</section>
