@php
    use App\Enums\AssignmentStatus;
    use App\Support\DateRange;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    @if ($clashByBooking)
        <div class="callout is-negative mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div class="flex-grow-1">Someone on this event is also booked elsewhere on the same day. Resolve it from the booking marked Clash.</div>
        </div>
    @endif

    @if ($canManage || $sheetDays)
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            @if ($canManage)
                <button type="button" class="btn btn-primary" wire:click="startCall">Add crew call</button>
                <x-ask-hive-button workflow="crew-suggestions" :subject="$event['code']" size="md" />
                @if ($firstHolds > 0)
                    <button type="button" class="btn btn-secondary" wire:click="confirmFirstHolds" wire:loading.attr="disabled" wire:confirm="Confirm every first hold on this event? Holds that can’t be confirmed stay as they are.">Confirm {{ $firstHolds }} first {{ $firstHolds === 1 ? 'hold' : 'holds' }}</button>
                @endif
            @endif
            @if ($canSeeSheets && $sheetDays)
                <div class="d-flex align-items-center gap-2 ms-md-auto flex-wrap">
                    <label for="sheet-date" class="small text-muted text-nowrap">Call sheet for</label>
                    <select id="sheet-date" class="form-select form-select-sm w-auto" wire:model.live="sheetDate">
                        @foreach ($sheetDays as $d)
                            <option value="{{ $d }}">{{ Display::date($d, weekday: true) }}</option>
                        @endforeach
                    </select>
                    <a href="{{ route('call-sheets.show', [$event['code'], $sheetDate]) }}" class="btn btn-secondary btn-sm" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="sendCallSheet" wire:loading.attr="disabled" wire:confirm="Email this call sheet to everyone booked that day?">Email to crew</button>
                </div>
            @endif
        </div>
    @endif

    @if ($crewCalls->isEmpty())
        <div class="card"><x-empty title="No crew calls yet">Add a crew call for each role you need, for example “2 × AV Technician, 4–6 Nov”. Booked people then appear here and on the rota.</x-empty></div>
    @endif

    <div class="d-grid gap-3">
        @foreach ($crewCalls as $call)
            <section class="card" wire:key="call-{{ $call['id'] }}">
                <div class="card-header flex-wrap">
                    <h2 class="card-title">{{ $call['quantity'] }} × {{ $call['role_name'] }}</h2>
                    <span class="small text-muted tnum">{{ DateRange::of($call['start_date'], $call['end_date'])->format() }}@if ($call['phase']), {{ ucfirst($call['phase']) }}@endif, call {{ $call['call_time'] }} to {{ $call['wrap_time'] }}</span>
                    @if ($call['skill_id'])
                        <span class="small text-muted">{{ $workspace->skills()->get($call['skill_id'])['name'] ?? '' }} level {{ $call['min_skill_level'] }}+</span>
                    @endif
                    <span class="ms-auto d-flex align-items-center gap-2">
                        <x-pill :tone="$call['filled'] >= $call['quantity'] ? 'positive' : 'warning'">{{ $call['filled'] >= $call['quantity'] ? 'Filled' : ($call['filled'] ? 'Partially filled' : 'Open') }}, {{ $call['filled'] }} of {{ $call['quantity'] }}</x-pill>
                        @if ($canManage)
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="startBooking({{ $call['id'] }})">Book someone</button>
                            <div class="dropdown">
                                <button type="button" class="icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More for {{ $call['role_name'] }}"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item" wire:click="startCall({{ $call['id'] }})">Edit crew call</button></li>
                                    <li><a class="dropdown-item" href="{{ route('availability', ['role' => $call['role_name'], 'from' => $call['start_date'], 'to' => $call['end_date'], 'skill' => $call['skill_id'], 'level' => $call['min_skill_level'] ?? 0, 'call' => $call['id']]) }}" wire:navigate>Search availability</a></li>
                                    @if ($call['assignments']->filter(fn ($a) => AssignmentStatus::from($a['status'])->holdsPerson())->isEmpty())
                                        <li><button type="button" class="dropdown-item text-negative" wire:click="deleteCall({{ $call['id'] }})" wire:confirm="Remove this crew call?">Remove crew call</button></li>
                                    @endif
                                </ul>
                            </div>
                        @endif
                    </span>
                </div>
                @if ($call['assignments']->isNotEmpty())
                    <div class="table-wrap border-0 rounded-0">
                        <table class="table">
                            <thead><tr><th>Person</th><th>Dates</th><th>Status</th>@if ($canSeeRates)<th class="num">Agreed day rate</th>@endif<th><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody>
                                @foreach ($call['assignments'] as $a)
                                    @php
                                        $status = AssignmentStatus::from($a['status']);
                                        $clashKey = $clashByBooking['assignment:'.$a['id']] ?? null;
                                        $expired = $workspace->expiredMandatoryDocuments($a['person_id']);
                                        $flags = $status->holdsPerson() ? ($warnings[$a['id']] ?? []) : [];
                                    @endphp
                                    <tr wire:key="as-{{ $a['id'] }}" @class(['text-muted' => ! $status->holdsPerson()])>
                                        <td>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <x-avatar :name="$workspace->personName($a['person_id'])" :id="$a['person_id']" size="sm" />
                                                <a href="{{ route('people.show', $a['person_id']) }}" wire:navigate>{{ $workspace->personName($a['person_id']) }}</a>
                                                @if ($expired->isNotEmpty())
                                                    <span class="flag is-negative" title="{{ $expired->pluck('title')->implode(', ') }} expired"><i class="bi bi-file-earmark-x" aria-hidden="true"></i>Document expired</span>
                                                @endif
                                            </div>
                                            @foreach ($flags as $flag)
                                                <div class="small text-warning-ink mt-1"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>{{ $flag }}</div>
                                            @endforeach
                                        </td>
                                        <td class="tnum text-nowrap">{{ DateRange::of($a['start_date'], $a['end_date'])->format() }}</td>
                                        <td>
                                            <x-status :value="$status" />
                                            @if ($status->holdsPerson() && $a['responded_at'] === null)
                                                <span class="small text-muted d-block">Request sent, no answer yet</span>
                                            @endif
                                            @if ($clashKey)
                                                <x-pill tone="negative" icon="exclamation-octagon">Clash</x-pill>
                                            @endif
                                            @if ($a['clash_override_reason'] ?? null)
                                                <span class="small text-muted d-block">Overlap accepted: {{ $a['clash_override_reason'] }}</span>
                                            @endif
                                            @if ($clashKey && ($a['clash_note'] ?? null))
                                                <span class="small text-muted d-block">Booked over the clash: {{ $a['clash_note'] }}</span>
                                            @endif
                                        </td>
                                        @if ($canSeeRates)
                                            <td class="num">{{ ! empty($a['agreed_rate_minor']) ? Money::of($a['agreed_rate_minor'])->format() : '—' }}</td>
                                        @endif
                                        <td class="text-end text-nowrap">
                                            @if ($canManage && $status->holdsPerson())
                                                @if ($clashKey)
                                                    <button type="button" class="btn btn-primary btn-sm" wire:click="$dispatch('resolve-clash', { key: '{{ $clashKey }}' })">Resolve</button>
                                                @endif
                                                @if ($status !== AssignmentStatus::Confirmed && $a['responded_at'] !== null)
                                                    <button type="button" class="btn btn-secondary btn-sm" wire:click="confirm({{ $a['id'] }})" wire:loading.attr="disabled">Confirm</button>
                                                @endif
                                                <button type="button" class="btn btn-quiet btn-sm" wire:click="release({{ $a['id'] }})" wire:confirm="Release {{ $workspace->personName($a['person_id']) }} from this booking? They will be told.">Release</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="card-body text-muted">Nobody booked yet.@if ($canManage) <button type="button" class="btn btn-link link p-0 align-baseline" wire:click="startBooking({{ $call['id'] }})">Book someone</button>@endif</div>
                @endif
                @if ($call['notes'])
                    <div class="card-body pt-0"><p class="note small mb-0">{{ $call['notes'] }}</p></div>
                @endif
            </section>
        @endforeach
    </div>

    @if ($canManage)
        <x-modal id="call-modal" :title="$editingCallId ? 'Edit crew call' : 'Add a crew call'" size="lg">
            <form id="call-form" class="row g-3" wire:submit="saveCall" novalidate>
                <x-form-field name="role_name" model="callForm.role_name" label="Role" list="crew-roles" placeholder="For example AV Technician" />
                <x-form-field name="quantity" model="callForm.quantity" label="How many people" type="number" min="1" />
                <x-form-field name="start_date" model="callForm.start_date" label="From" type="date" />
                <x-form-field name="end_date" model="callForm.end_date" label="To" type="date" />
                <x-form-field name="call_time" model="callForm.call_time" label="Call time" type="time" col="col-sm-3 col-6" />
                <x-form-field name="wrap_time" model="callForm.wrap_time" label="Wrap time" type="time" col="col-sm-3 col-6" />
                <div class="col-sm-6">
                    <label for="c-phase" class="form-label">Phase</label>
                    <select id="c-phase" class="form-select" wire:model="callForm.phase">
                        <option value="">Not set</option>
                        @foreach ($phaseTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="c-skill" class="form-label">Skill needed (optional)</label>
                    <select id="c-skill" @class(['form-select', 'is-invalid' => $errors->has('callForm.skill_id')]) wire:model="callForm.skill_id">
                        <option value="">None</option>
                        @foreach ($skills as $s)
                            <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6">
                    <label for="c-level" class="form-label">Minimum level</label>
                    <select id="c-level" @class(['form-select', 'is-invalid' => $errors->has('callForm.min_skill_level')]) wire:model="callForm.min_skill_level">
                        <option value="">Any</option>
                        @foreach ([1, 2, 3, 4, 5] as $l)
                            <option value="{{ $l }}">{{ $l }} or above</option>
                        @endforeach
                    </select>
                    @error('callForm.min_skill_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <x-form-field name="location" model="callForm.location" label="Report to (optional)" placeholder="For example Loading bay, Hall 3" />
                <x-form-field name="budgeted_rate" model="callForm.budgeted_rate" label="Budgeted day rate (optional)" inputmode="decimal" />
                <x-form-field name="notes" model="callForm.notes" label="Notes (optional)" type="textarea" col="col-12" />
                <datalist id="crew-roles">
                    @foreach ($workspace->people()->pluck('job_title')->unique()->sort() as $role)
                        <option value="{{ $role }}"></option>
                    @endforeach
                </datalist>
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="call-form" class="btn btn-primary" wire:loading.attr="disabled">{{ $editingCallId ? 'Save crew call' : 'Add crew call' }}</button>
            </x-slot:footer>
        </x-modal>

        <x-modal id="book-modal" :title="isset($bookingCall) ? 'Book someone as '.$bookingCall->role_name : 'Book someone'" size="lg">
            @isset($bookingCall)
                <form id="book-form" class="row g-3" wire:submit="book" novalidate>
                    <div class="col-12">
                        <div class="small text-muted mb-2">Free on those dates, {{ $bookingCall->skill_id ? 'with the skill, ' : '' }}nearest first</div>
                        @if ($suggestions->isNotEmpty())
                            <div class="list-group" role="radiogroup" aria-label="Suggested people">
                                @foreach ($suggestions as $s)
                                    <label class="list-group-item d-flex align-items-center gap-2" wire:key="sug-{{ $s['person']['id'] }}">
                                        <input class="form-check-input mt-0" type="radio" value="{{ $s['person']['id'] }}" wire:model.live="booking.person_id">
                                        <span class="flex-grow-1">{{ $workspace->personName($s['person']['id']) }} <span class="small text-muted">{{ collect([$s['person']['location_city'], $bookingCall->skill_id ? 'level '.$s['skill_level'] : null, $s['rating'] ? 'rated '.$s['rating'] : null])->filter()->implode(', ') }}</span></span>
                                        @if ($canSeeRates && $s['rate'])
                                            <span class="small tnum">{{ $s['rate']->format() }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <p class="text-muted mb-0">Nobody with this role is free on those dates. Choose someone below and record why the overlap is acceptable.</p>
                        @endif
                    </div>
                    <div class="col-12">
                        <label for="b-person" class="form-label">Or anyone</label>
                        <select id="b-person" @class(['form-select', 'is-invalid' => $errors->has('booking.person_id')]) wire:model.live="booking.person_id">
                            <option value="">Choose…</option>
                            @foreach ($everyone as $p)
                                <option value="{{ $p->id }}">{{ $p->displayName() }}, {{ $p->job_title }}</option>
                            @endforeach
                        </select>
                        @error('booking.person_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label for="b-start" class="form-label">From</label>
                        <input id="b-start" type="date" min="{{ $bookingCall->start_date->toDateString() }}" max="{{ $bookingCall->end_date->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('booking.start_date')]) wire:model.live="booking.start_date">
                        @error('booking.start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label for="b-end" class="form-label">To</label>
                        <input id="b-end" type="date" min="{{ $bookingCall->start_date->toDateString() }}" max="{{ $bookingCall->end_date->toDateString() }}" @class(['form-control', 'is-invalid' => $errors->has('booking.end_date')]) wire:model.live="booking.end_date">
                        @error('booking.end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label for="b-status" class="form-label">Book as</label>
                        <select id="b-status" @class(['form-select', 'is-invalid' => $errors->has('booking.status')]) wire:model.live="booking.status">
                            <option value="second-hold">Second hold</option>
                            <option value="first-hold">First hold</option>
                            @unless ($bookingIsFreelancer)
                                <option value="confirmed">Confirmed</option>
                            @endunless
                        </select>
                        @error('booking.status')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    @if ($preview)
                        @if ($preview['request'])
                            <div class="col-12 small text-muted">They’re a freelancer, so they get a booking request with the dates, location, role and rate. Accepting confirms it once the event is confirmed.</div>
                        @endif
                        @foreach ($preview['blockers'] as $b)
                            <div class="col-12"><div class="callout is-warning mb-0"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>{{ $b }}</div></div></div>
                        @endforeach
                        @if ($preview['clashes'])
                            <div class="col-12">
                                <div class="callout is-negative mb-2">
                                    <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                                    <div><strong>This would clash.</strong> @foreach ($preview['clashes'] as $c){{ $c }} @endforeach <span class="d-block small mt-1">A hold booked over a clash shows on the rota until it’s resolved. Confirmed over confirmed, your reason records the overlap as accepted.</span></div>
                                </div>
                                <label for="b-reason" class="form-label">Why both bookings can stand</label>
                                <textarea id="b-reason" rows="2" @class(['form-control', 'is-invalid' => $errors->has('booking.clash_override_reason')]) wire:model="booking.clash_override_reason" placeholder="For example Morning build only; the evening show is a five-minute walk away"></textarea>
                                @error('booking.clash_override_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif
                        @if ($preview['warnings'])
                            <div class="col-12">
                                <div class="callout is-warning mb-0">
                                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                                    <div>
                                        <strong>Working time</strong>
                                        <ul class="mb-0 ps-3">@foreach ($preview['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
                                        <div class="small text-muted mt-1">Warnings against UK Working Time Regulations reference values, not legal advice.</div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif
                </form>
            @endisset
            <x-slot:footer>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="book-form" class="btn btn-primary" wire:loading.attr="disabled">{{ ($bookingIsFreelancer ?? false) ? 'Send booking request' : 'Book' }}</button>
            </x-slot:footer>
        </x-modal>

        <livewire:resourcing.resolve-clash-modal />
    @endif
</div>
