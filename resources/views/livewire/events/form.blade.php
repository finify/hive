@php
    $err = fn (string $key) => $errors->first($key);
@endphp
<div>
    <x-page-head :title="$event ? 'Edit '.$event['name'] : 'Add an enquiry'" :answer="$event ? 'Changes are logged with who made them. Move the stage from the event page.' : 'New events start as an enquiry with the code '.$nextCode.'. Dates, client and fee can follow.'">
        <x-slot:crumbs>
            <a href="{{ route('events.index') }}" wire:navigate>Events</a>
            @if ($event)
                <span aria-hidden="true">/</span> <a href="{{ route('events.show', $event['code']) }}" wire:navigate class="code">{{ $event['code'] }}</a>
            @endif
        </x-slot:crumbs>
    </x-page-head>

    <form wire:submit="save" class="d-grid gap-3 mw-form" novalidate>
        <section class="card">
            <div class="card-header"><h2 class="card-title">The event</h2></div>
            <div class="card-body row g-3">
                <x-form-field name="name" label="Name" col="col-12" placeholder="For example Northwind Partner Summit 2027" />
                <div class="col-sm-6">
                    <label for="f-type" class="form-label">Type</label>
                    <select id="f-type" @class(['form-select', 'is-invalid' => $err('form.event_type')]) wire:model="form.event_type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($err('form.event_type'))<div class="invalid-feedback">{{ $err('form.event_type') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-client" class="form-label">Client</label>
                    <select id="f-client" @class(['form-select', 'is-invalid' => $err('form.client_id')]) wire:model="form.client_id">
                        <option value="">Not known yet</option>
                        @foreach ($clients as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @if ($err('form.client_id'))<div class="invalid-feedback">{{ $err('form.client_id') }}</div>@endif
                </div>
                <div class="col-sm-6">
                    <label for="f-producer" class="form-label">Producer</label>
                    <select id="f-producer" @class(['form-select', 'is-invalid' => $err('form.producer_id')]) wire:model="form.producer_id">
                        <option value="">Nobody yet</option>
                        @foreach ($staff as $p)
                            <option value="{{ $p->id }}">{{ $p->displayName() }}</option>
                        @endforeach
                    </select>
                    @if ($err('form.producer_id'))<div class="invalid-feedback">{{ $err('form.producer_id') }}</div>@endif
                </div>
                <x-form-field name="expected_attendance" label="Expected attendance (optional)" type="number" min="1" inputmode="numeric" />
                <x-form-field name="description" label="Description (optional)" type="textarea" col="col-12" />
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2 class="card-title">Where and when</h2></div>
            <div class="card-body row g-3">
                <x-form-field name="start_date" label="Starts" type="date" hint="Outline dates are needed before a proposal is sent." />
                <x-form-field name="end_date" label="Ends" type="date" />
                <div class="col-sm-6">
                    <label for="f-venue" class="form-label">Venue</label>
                    <select id="f-venue" @class(['form-select', 'is-invalid' => $err('form.venue_id')]) wire:model.live="form.venue_id">
                        <option value="">Not chosen yet</option>
                        @foreach ($venues as $v)
                            <option value="{{ $v->id }}">{{ $v->name }}, {{ $v->city }}</option>
                        @endforeach
                    </select>
                    @if ($err('form.venue_id'))<div class="invalid-feedback">{{ $err('form.venue_id') }}</div>@endif
                </div>
                <x-form-field name="timezone" label="Timezone" placeholder="Europe/London" />
                <x-form-field name="city" label="City" />
                <x-form-field name="country" label="Country" />
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h2 class="card-title">Money</h2></div>
            <div class="card-body row g-3">
                <x-form-field name="client_fee" label="Client fee or estimated budget" inputmode="decimal" placeholder="For example 85000" hint="Needed before a proposal is sent, and agreed before the event is confirmed." />
                <div class="col-sm-6">
                    <label for="f-currency" class="form-label">Currency</label>
                    <select id="f-currency" class="form-select" wire:model="form.currency">
                        @foreach ($currencies as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Phases</h2>
                <button type="button" class="btn btn-secondary btn-sm ms-auto" wire:click="addPhase">Add a phase</button>
            </div>
            <div class="card-body">
                @forelse ($phases as $i => $phase)
                    <div class="row g-2 align-items-end mb-2" wire:key="phase-{{ $i }}">
                        <div class="col-sm-3">
                            <label for="f-phase-{{ $i }}" class="form-label small">Phase</label>
                            <select id="f-phase-{{ $i }}" class="form-select" wire:model="phases.{{ $i }}.type">
                                @foreach ($phaseTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-3 col-6">
                            <label for="f-phase-start-{{ $i }}" class="form-label small">From</label>
                            <input id="f-phase-start-{{ $i }}" type="date" @class(['form-control', 'is-invalid' => $err('phases.'.$i.'.start_date')]) wire:model="phases.{{ $i }}.start_date">
                            @if ($err('phases.'.$i.'.start_date'))<div class="invalid-feedback">{{ $err('phases.'.$i.'.start_date') }}</div>@endif
                        </div>
                        <div class="col-sm-3 col-6">
                            <label for="f-phase-end-{{ $i }}" class="form-label small">To</label>
                            <input id="f-phase-end-{{ $i }}" type="date" @class(['form-control', 'is-invalid' => $err('phases.'.$i.'.end_date')]) wire:model="phases.{{ $i }}.end_date">
                            @if ($err('phases.'.$i.'.end_date'))<div class="invalid-feedback">{{ $err('phases.'.$i.'.end_date') }}</div>@endif
                        </div>
                        <div class="col-sm-3">
                            <button type="button" class="btn btn-quiet w-100" wire:click="removePhase({{ $i }})" aria-label="Remove phase {{ $i + 1 }}">Remove</button>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No phases yet. Add travel, build, rehearsal, live and breakdown once the dates are firm; the rota and crew calls use them.</p>
                @endforelse
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Team</h2>
                <button type="button" class="btn btn-secondary btn-sm ms-auto" wire:click="addMember">Add someone</button>
            </div>
            <div class="card-body">
                @forelse ($team as $i => $member)
                    <div class="row g-2 align-items-end mb-2" wire:key="member-{{ $i }}">
                        <div class="col-sm-5">
                            <label for="f-member-{{ $i }}" class="form-label small">Person</label>
                            <select id="f-member-{{ $i }}" @class(['form-select', 'is-invalid' => $err('team.'.$i.'.person_id')]) wire:model="team.{{ $i }}.person_id">
                                <option value="">Choose…</option>
                                @foreach ($people as $p)
                                    <option value="{{ $p->id }}">{{ $p->displayName() }}</option>
                                @endforeach
                            </select>
                            @if ($err('team.'.$i.'.person_id'))<div class="invalid-feedback">{{ $err('team.'.$i.'.person_id') }}</div>@endif
                        </div>
                        <div class="col-sm-4">
                            <label for="f-role-{{ $i }}" class="form-label small">Role on this event</label>
                            <input id="f-role-{{ $i }}" list="team-roles" @class(['form-control', 'is-invalid' => $err('team.'.$i.'.role')]) wire:model="team.{{ $i }}.role" placeholder="For example Coordinator">
                            @if ($err('team.'.$i.'.role'))<div class="invalid-feedback">{{ $err('team.'.$i.'.role') }}</div>@endif
                        </div>
                        <div class="col-sm-3">
                            <button type="button" class="btn btn-quiet w-100" wire:click="removeMember({{ $i }})" aria-label="Remove team member {{ $i + 1 }}">Remove</button>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">Nobody on the team yet. Team roles decide who gets each task from the standard list.</p>
                @endforelse
                <datalist id="team-roles">
                    @foreach (['Producer', 'Coordinator', 'Production manager', 'Technical production manager', 'Stage manager', 'Registration lead', 'Designer', 'Logistics', 'Finance contact'] as $role)
                        <option value="{{ $role }}"></option>
                    @endforeach
                </datalist>
            </div>
        </section>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $event ? 'Save changes' : 'Add enquiry' }}</button>
            <a href="{{ $event ? route('events.show', $event['code']) : route('events.index') }}" class="btn btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
