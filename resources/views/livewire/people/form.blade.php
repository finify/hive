@php
    $err = fn (string $key) => $errors->first($key);
@endphp
<div>
    <x-page-head :title="$person ? 'Edit '.$person->displayName() : 'Add a person'" :answer="$person ? 'Changes are logged with who made them.' : 'Staff and freelancers share one record. Freelancers also get company, VAT and a starting day rate.'">
        <x-slot:crumbs>
            <a href="{{ route('people.index') }}" wire:navigate>Directory</a>
            @if ($person)
                <span aria-hidden="true">/</span> <a href="{{ route('people.show', $person->id) }}" wire:navigate>{{ $person->displayName() }}</a>
            @endif
        </x-slot:crumbs>
    </x-page-head>

    <form wire:submit="save" class="d-grid gap-3 mw-form" novalidate>
        <section class="card">
            <div class="card-header"><h2 class="card-title">Who they are</h2></div>
            <div class="card-body row g-3">
                @foreach ([['first_name', 'First name', 'given-name'], ['last_name', 'Last name', 'family-name'], ['preferred_name', 'Preferred name (optional)', 'nickname'], ['pronouns', 'Pronouns (optional)', 'off']] as [$field, $label, $auto])
                    <div class="col-sm-6">
                        <label for="f-{{ $field }}" class="form-label">{{ $label }}</label>
                        <input id="f-{{ $field }}" @class(['form-control', 'is-invalid' => $err('form.'.$field)]) wire:model="form.{{ $field }}" autocomplete="{{ $auto }}">
                        @if ($err('form.'.$field))<div class="invalid-feedback">{{ $err('form.'.$field) }}</div>@endif
                    </div>
                @endforeach
                <div class="col-sm-6">
                    <label for="f-email" class="form-label">Work email</label>
                    <input id="f-email" type="email" @class(['form-control', 'is-invalid' => $err('form.email')]) wire:model="form.email">
                    @if ($err('form.email'))<div class="invalid-feedback">{{ $err('form.email') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-phone" class="form-label">Phone (optional)</label>
                    <input id="f-phone" type="tel" class="form-control" wire:model="form.phone">
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2 class="card-title">Work</h2></div>
            <div class="card-body row g-3">
                <div class="col-sm-6">
                    <label for="f-type" class="form-label">Employment type</label>
                    <select id="f-type" class="form-select" wire:model.live="form.employment_type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="f-title" class="form-label">Job title or role</label>
                    <input id="f-title" @class(['form-control', 'is-invalid' => $err('form.job_title')]) wire:model="form.job_title" placeholder="For example AV Technician">
                    @if ($err('form.job_title'))<div class="invalid-feedback">{{ $err('form.job_title') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-dept" class="form-label">Department</label>
                    <select id="f-dept" class="form-select" wire:model="form.department_id">
                        <option value="">None</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="f-manager" class="form-label">Line manager</label>
                    <select id="f-manager" @class(['form-select', 'is-invalid' => $err('form.line_manager_id')]) wire:model="form.line_manager_id">
                        <option value="">None</option>
                        @foreach ($managers as $m)
                            <option value="{{ $m->id }}">{{ $m->displayName() }}</option>
                        @endforeach
                    </select>
                    @if ($err('form.line_manager_id'))<div class="invalid-feedback">{{ $err('form.line_manager_id') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-city" class="form-label">Based in (city)</label>
                    <input id="f-city" @class(['form-control', 'is-invalid' => $err('form.location_city')]) wire:model="form.location_city">
                    @if ($err('form.location_city'))<div class="invalid-feedback">{{ $err('form.location_city') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-country" class="form-label">Country</label>
                    <input id="f-country" @class(['form-control', 'is-invalid' => $err('form.location_country')]) wire:model="form.location_country">
                </div>
                <div class="col-sm-6">
                    <label for="f-start" class="form-label">Start date</label>
                    <input id="f-start" type="date" @class(['form-control', 'is-invalid' => $err('form.start_date')]) wire:model="form.start_date">
                    @if ($err('form.start_date'))<div class="invalid-feedback">{{ $err('form.start_date') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-end" class="form-label">End date (optional)</label>
                    <input id="f-end" type="date" @class(['form-control', 'is-invalid' => $err('form.end_date')]) wire:model="form.end_date">
                    @if ($err('form.end_date'))<div class="invalid-feedback">{{ $err('form.end_date') }}</div>@endif
                </div>
                <div class="col-12">
                    <label for="f-langs" class="form-label">Languages</label>
                    <input id="f-langs" class="form-control" wire:model="form.languages" placeholder="English, Portuguese">
                </div>
            </div>
        </section>

        @if ($isFreelance)
            <section class="card">
                <div class="card-header"><h2 class="card-title">Freelance details</h2></div>
                <div class="card-body row g-3">
                    <div class="col-sm-6">
                        <label for="f-company" class="form-label">Company name (optional)</label>
                        <input id="f-company" class="form-control" wire:model="form.company_name">
                    </div>
                    <div class="col-sm-6">
                        <label for="f-terms" class="form-label">Payment terms (days)</label>
                        <input id="f-terms" type="number" min="0" class="form-control tnum" wire:model="form.payment_terms_days">
                    </div>
                    <div class="col-sm-6 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="f-vat" wire:model.live="form.vat_registered">
                            <label class="form-check-label" for="f-vat">VAT registered</label>
                        </div>
                    </div>
                    @if ($form['vat_registered'] ?? false)
                        <div class="col-sm-6">
                            <label for="f-vatno" class="form-label">VAT number</label>
                            <input id="f-vatno" @class(['form-control', 'is-invalid' => $err('form.vat_number')]) wire:model="form.vat_number">
                            @if ($err('form.vat_number'))<div class="invalid-feedback">{{ $err('form.vat_number') }}</div>@endif
                        </div>
                    @endif
                    @if (! $person)
                        <div class="col-12"><h3 class="fs-6 fw-medium mb-0 mt-2">Starting day rate</h3><p class="small text-muted mb-0">Later changes are added by finance as a new rate from a date, so costs already posted never change.</p></div>
                        <div class="col-sm-5">
                            <label for="f-rate-role" class="form-label">Role</label>
                            <input id="f-rate-role" class="form-control" wire:model="rate.role_name" placeholder="Same as job title">
                        </div>
                        <div class="col-sm-3">
                            <label for="f-rate" class="form-label">Day rate (£)</label>
                            <input id="f-rate" inputmode="decimal" @class(['form-control tnum', 'is-invalid' => $err('rate.amount')]) wire:model="rate.amount" placeholder="400">
                            @if ($err('rate.amount'))<div class="invalid-feedback">{{ $err('rate.amount') }}</div>@endif
                        </div>
                        <div class="col-sm-4">
                            <label for="f-rate-from" class="form-label">From</label>
                            <input id="f-rate-from" type="date" class="form-control" wire:model="rate.effective_from">
                        </div>
                    @endif
                </div>
            </section>
        @endif

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Skills</h2>
                <button type="button" class="btn btn-secondary btn-sm ms-auto" wire:click="addSkill">Add a skill</button>
            </div>
            <div class="card-body">
                @forelse ($skills as $i => $skill)
                    <div class="row g-2 align-items-end mb-2" wire:key="skill-{{ $i }}">
                        <div class="col-7">
                            <label for="f-skill-{{ $i }}" class="form-label small">Skill</label>
                            <select id="f-skill-{{ $i }}" @class(['form-select', 'is-invalid' => $err('skills.'.$i.'.skill_id')]) wire:model="skills.{{ $i }}.skill_id">
                                <option value="">Choose…</option>
                                @foreach ($skillOptions as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                            @if ($err('skills.'.$i.'.skill_id'))<div class="invalid-feedback">{{ $err('skills.'.$i.'.skill_id') }}</div>@endif
                        </div>
                        <div class="col-3">
                            <label for="f-level-{{ $i }}" class="form-label small">Level, 1 to 5</label>
                            <select id="f-level-{{ $i }}" class="form-select" wire:model="skills.{{ $i }}.level">
                                @foreach ([1, 2, 3, 4, 5] as $l)
                                    <option value="{{ $l }}">{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-2">
                            <button type="button" class="btn btn-quiet w-100" wire:click="removeSkill({{ $i }})" aria-label="Remove skill">Remove</button>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No skills yet. Add the ones producers search for, like Disguise media server or IPAF, with a level from 1 to 5.</p>
                @endforelse
            </div>
        </section>

        @if ($canRestricted)
            <section class="card">
                <div class="card-header"><h2 class="card-title">Restricted details</h2><span class="small text-muted ms-auto">HR and the person only. Encrypted at rest.</span></div>
                <div class="card-body row g-3">
                    @foreach ([['emergency_contact', 'Emergency contact', 'text', 'col-12'], ['nationality', 'Nationality', 'text', 'col-sm-6'], ['passport_number', 'Passport number', 'text', 'col-sm-6'], ['passport_expiry', 'Passport expiry', 'date', 'col-sm-6'], ['dietary_requirements', 'Dietary requirements', 'text', 'col-sm-6'], ['access_needs', 'Access needs', 'text', 'col-sm-6']] as [$field, $label, $type, $col])
                        <div class="{{ $col }}">
                            <label for="r-{{ $field }}" class="form-label">{{ $label }}</label>
                            <input id="r-{{ $field }}" type="{{ $type }}" @class(['form-control', 'is-invalid' => $err('restricted.'.$field)]) wire:model="restricted.{{ $field }}" autocomplete="off">
                            @if ($err('restricted.'.$field))<div class="invalid-feedback">{{ $err('restricted.'.$field) }}</div>@endif
                        </div>
                    @endforeach
                    <div class="col-12">
                        <label for="f-notes" class="form-label">Confidential notes</label>
                        <textarea id="f-notes" rows="2" class="form-control" wire:model="form.notes"></textarea>
                    </div>
                </div>
            </section>
        @endif

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $person ? 'Save changes' : 'Add person' }}</button>
            <a href="{{ $person ? route('people.show', $person->id) : route('people.index') }}" class="btn btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
