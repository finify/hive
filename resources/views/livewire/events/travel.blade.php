@php
    use App\Enums\TravelKind;
    use App\Enums\TravelMode;
    use App\Support\Display;
    use App\Support\Money;
    $kind = TravelKind::tryFrom($travelForm['kind'] ?? '') ?? TravelKind::Outbound;
@endphp
<div>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="text-muted small me-auto tnum">
            {{ $travellers }} {{ $travellers === 1 ? 'person' : 'people' }} travelling, {{ $nights }} hotel {{ $nights === 1 ? 'night' : 'nights' }}@if ($showCosts && $totals), {{ $totals }} booked (actual cost on the Travel and Accommodation lines)@endif.
        </div>
        @if ($canManage)
            <button type="button" class="btn btn-primary" wire:click="startBooking">Add travel</button>
            <a href="{{ route('travel.manifest', $event['code']) }}" class="btn btn-secondary"><i class="bi bi-download me-1" aria-hidden="true"></i>Manifest (CSV)</a>
        @endif
    </div>

    @if ($bookings->isEmpty())
        <div class="card"><x-empty title="No travel yet">Flights, trains, transfers and hotels for the crew appear here, with a manifest to send to the travel agent. Costs go to the budget as they are booked.</x-empty></div>
    @else
        <div class="d-grid gap-3">
            @foreach ($bookings as $personId => $legs)
                <section class="card" wire:key="tp-{{ $personId }}">
                    <div class="card-header flex-wrap">
                        <x-avatar :name="$people[$personId] ?? app(\App\Demo\Workspace::class)->personName($personId)" :id="$personId" size="sm" />
                        <h2 class="card-title flex-grow-1">{{ $people[$personId] ?? app(\App\Demo\Workspace::class)->personName($personId) }}</h2>
                        @if ($canManage)
                            <button type="button" class="btn btn-quiet btn-sm" wire:click="startBooking({{ $personId }}, 'accommodation')">Add hotel</button>
                            <button type="button" class="btn btn-quiet btn-sm" wire:click="startBooking({{ $personId }})">Add journey</button>
                        @endif
                    </div>
                    <ul class="list-rows">
                        @foreach ($legs as $b)
                            <li wire:key="tb-{{ $b->id }}" @class(['text-muted' => $b->status->value === 'cancelled'])>
                                <i class="bi bi-{{ $b->mode->icon() }}" aria-hidden="true"></i>
                                <div class="row-main">
                                    <div @class(['row-title', 'text-decoration-line-through' => $b->status->value === 'cancelled'])>
                                        @if ($b->kind === TravelKind::Accommodation)
                                            {{ $b->provider }}, {{ Display::date($b->check_in) }} to {{ Display::date($b->check_out) }}
                                        @else
                                            {{ $b->kind->label() }}: {{ $b->from_place }} to {{ $b->to_place }}
                                        @endif
                                    </div>
                                    <div class="row-sub tnum">
                                        @if ($b->kind === TravelKind::Accommodation)
                                            {{ $b->check_in->diffInDays($b->check_out) }} {{ $b->check_in->diffInDays($b->check_out) == 1 ? 'night' : 'nights' }}
                                        @else
                                            {{ $b->mode->label() }}{{ $b->provider ? ', '.$b->provider : '' }} · {{ Display::date($b->depart_at, true) }} {{ $b->depart_at?->format('H:i') }}@if ($b->arrive_at), arrives {{ $b->arrive_at->format('H:i') }}@endif
                                        @endif
                                        @if ($b->reference) · Ref {{ $b->reference }}@endif
                                    </div>
                                </div>
                                @if ($b->status->value === 'cancelled')
                                    <x-status :value="$b->status" />
                                @else
                                    @if ($showCosts && $b->cost_minor)
                                        <span class="tnum small">{{ Money::of($b->cost_minor, $b->currency)->format() }}</span>
                                    @endif
                                    @if ($canManage)
                                        <button type="button" class="btn btn-quiet btn-sm" wire:click="edit({{ $b->id }})">Change</button>
                                        <button type="button" class="btn btn-quiet btn-sm text-negative" wire:click="cancelBooking({{ $b->id }})" wire:confirm="Cancel this booking? Its cost comes off the budget.">Cancel</button>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif

    @if ($canManage)
        <x-modal id="travel-modal" :title="$editing ? 'Change travel' : 'Add travel'" size="lg">
            <form id="travel-form" class="row g-3" wire:submit="save" novalidate>
                <div class="col-sm-6">
                    <label for="tr-person" class="form-label">Who</label>
                    <select id="tr-person" @class(['form-select', 'is-invalid' => $errors->has('travelForm.person_id')]) wire:model="travelForm.person_id">
                        <option value="">Choose a person</option>
                        @foreach ($people as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('travelForm.person_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-3">
                    <label for="tr-kind" class="form-label">What</label>
                    <select id="tr-kind" class="form-select" wire:model.live="travelForm.kind">
                        @foreach (TravelKind::cases() as $k)
                            <option value="{{ $k->value }}">{{ $k->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3">
                    <label for="tr-mode" class="form-label">How</label>
                    <select id="tr-mode" @class(['form-select', 'is-invalid' => $errors->has('travelForm.mode')]) wire:model="travelForm.mode">
                        @foreach ($kind->modes() as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                    @error('travelForm.mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @if ($kind === TravelKind::Accommodation)
                    <x-form-field name="provider" model="travelForm.provider" label="Hotel" col="col-sm-6" />
                    <x-form-field name="to_place" model="travelForm.to_place" label="City (optional)" col="col-sm-6" />
                    <x-form-field name="check_in" model="travelForm.check_in" label="Check-in" type="date" />
                    <x-form-field name="check_out" model="travelForm.check_out" label="Check-out" type="date" />
                @else
                    <x-form-field name="from_place" model="travelForm.from_place" label="From" />
                    <x-form-field name="to_place" model="travelForm.to_place" label="To" />
                    <x-form-field name="depart_at" model="travelForm.depart_at" label="Departs (local time)" type="datetime-local" />
                    <x-form-field name="arrive_at" model="travelForm.arrive_at" label="Arrives (local time, optional)" type="datetime-local" />
                    <x-form-field name="provider" model="travelForm.provider" label="Airline or operator (optional)" />
                @endif
                <x-form-field name="reference" model="travelForm.reference" label="Booking reference (optional)" />
                <x-form-field name="cost" model="travelForm.cost" label="Cost" col="col-sm-4" inputmode="decimal" />
                <div class="col-sm-2">
                    <label for="tr-cur" class="form-label">Currency</label>
                    <select id="tr-cur" class="form-select" wire:model="travelForm.currency">
                        @foreach ($currencies as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <x-form-field name="notes" model="travelForm.notes" label="Notes for the traveller (optional)" type="textarea" col="col-12" />
                <div class="col-12 small text-muted">The cost is posted as an actual on the event’s {{ $kind === TravelKind::Accommodation ? 'Accommodation' : 'Travel' }} line. The traveller is told.</div>
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="travel-form" class="btn btn-primary" wire:loading.attr="disabled">{{ $editing ? 'Save changes' : 'Add travel' }}</button>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
