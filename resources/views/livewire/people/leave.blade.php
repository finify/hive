@php
    use App\Enums\LeaveStatus;
    use App\Support\DateRange;
    use App\Support\Display;
    $workspace = app(\App\Demo\Workspace::class);
    $viewer = app(\App\Demo\Viewer::class);
@endphp
<div>
    <x-page-head title="Leave" :answer="$calendar->count().' people off or requesting time off from today. Sickness and other sensitive leave shows only as “Absent”.'">
        <x-slot:actions>
            <a href="{{ route('rota') }}" class="btn btn-secondary" wire:navigate>See it on the rota</a>
        </x-slot:actions>
    </x-page-head>

    @if (count($tabs) > 1)
        <nav class="tabs" aria-label="Leave">
            @foreach ($tabs as $key => $label)
                <button type="button" @class(['active' => $tab === $key]) wire:click="$set('tab', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </nav>
    @endif

    @if ($tab === 'overview')
        <div class="grid-12">
            <section class="card span-8">
                <div class="card-header"><h2 class="card-title">Who’s off</h2></div>
                @if ($calendar->isEmpty())
                    <x-empty title="Nobody is off">Approved and requested leave appears here and on the rota, so producers see it before they book.</x-empty>
                @else
                    <ul class="list-rows">
                        @foreach ($calendar as $l)
                            <li wire:key="cal-{{ $l['id'] }}">
                                <x-avatar :name="$workspace->personName($l['person_id'])" :id="$l['person_id']" size="sm" />
                                <div class="row-main">
                                    <div class="row-title">{{ $workspace->personName($l['person_id']) }}</div>
                                    <div class="row-sub tnum">{{ $viewer->leaveLabel($l) }}, {{ DateRange::of($l['start_date'], $l['end_date'])->format() }}</div>
                                </div>
                                <x-status :value="LeaveStatus::from($l['status'])" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="span-4 d-grid gap-3 align-content-start">
                @if ($balance)
                    <x-figure :label="'Annual leave left in '.$year" :value="Display::days($balance->remaining())"
                        :caption="Display::days($balance->taken).' taken and '.Display::days($balance->booked).' booked of '.Display::days($balance->entitlement()).($balance->carriedOver ? ' ('.Display::days($balance->carriedOver).' carried over)' : '').($balance->pending ? '; '.Display::days($balance->pending).' waiting for approval' : '')" />
                    @if ($balance->toilEarned > 0)
                        <x-figure label="Time off in lieu" :value="Display::days($balance->toilRemaining())" :caption="Display::days($balance->toilEarned).' earned, '.Display::days($balance->toilUsed).' taken'.($balance->toilPending ? ', '.Display::days($balance->toilPending).' requested' : '')" />
                    @endif
                @endif
                @can('leave.request')
                    <section class="card">
                        <div class="card-header"><h2 class="card-title">Ask for leave</h2></div>
                        <form class="card-body row g-3" wire:submit="requestLeave" novalidate>
                            <div class="col-12">
                                <label for="l-type" class="form-label">Type</label>
                                <select id="l-type" @class(['form-select', 'is-invalid' => $errors->has('leaveForm.leave_type')]) wire:model="leaveForm.leave_type">
                                    @foreach ($leaveTypes as $t)
                                        <option value="{{ $t['key'] }}">{{ $t['name'] }}</option>
                                    @endforeach
                                </select>
                                @error('leaveForm.leave_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <x-form-field name="start_date" model="leaveForm.start_date" label="From" type="date" col="col-6" />
                            <x-form-field name="end_date" model="leaveForm.end_date" label="To" type="date" col="col-6" />
                            <x-form-field name="reason" model="leaveForm.reason" label="Note for your manager (optional)" type="textarea" col="col-12" />
                            <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Send request</button></div>
                            <div class="col-12 small text-muted">Weekends and your region’s bank holidays aren’t counted. Your line manager decides; producers see the dates on the rota once it’s approved.</div>
                        </form>
                    </section>
                @endcan
                @if ($absentees->isNotEmpty())
                    <section class="card">
                        <div class="card-header"><h2 class="card-title">Record an absence</h2></div>
                        <form class="card-body row g-3" wire:submit="recordAbsence" novalidate>
                            <div class="col-12">
                                <label for="a-person" class="form-label">Who</label>
                                <select id="a-person" @class(['form-select', 'is-invalid' => $errors->has('absenceForm.person_id')]) wire:model="absenceForm.person_id">
                                    <option value="">Choose a person</option>
                                    @foreach ($absentees as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('absenceForm.person_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label for="a-type" class="form-label">Type</label>
                                <select id="a-type" class="form-select" wire:model="absenceForm.leave_type">
                                    @foreach ($leaveTypes as $t)
                                        <option value="{{ $t['key'] }}">{{ $t['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-form-field name="start_date" model="absenceForm.start_date" label="From" type="date" col="col-6" />
                            <x-form-field name="end_date" model="absenceForm.end_date" label="To" type="date" col="col-6" />
                            <div class="col-12"><button type="submit" class="btn btn-secondary" wire:loading.attr="disabled">Record absence</button></div>
                            <div class="col-12 small text-muted">For sickness and other absence not asked for in advance. Recorded as approved; only HR, the line manager and the person see the type.</div>
                        </form>
                    </section>
                @endif
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Next bank holidays</h2></div>
                    <ul class="list-rows">
                        @foreach ($bankHolidays as $b)
                            <li><div class="row-main">{{ $b->name }}<div class="row-sub">{{ $regions[$b->region] }}</div></div><span class="tnum small">{{ Display::date($b->date->toDateString(), true) }}</span></li>
                        @endforeach
                    </ul>
                </section>
            </div>

            @if ($mine->isNotEmpty())
                <section class="card span-12">
                    <div class="card-header"><h2 class="card-title">My requests</h2></div>
                    <ul class="list-rows">
                        @foreach ($mine as $l)
                            <li wire:key="mine-{{ $l['id'] }}">
                                <div class="row-main"><div class="row-title">{{ $viewer->leaveLabel($l) }}</div><div class="row-sub tnum">{{ DateRange::of($l['start_date'], $l['end_date'])->format() }}, {{ Display::days($l['days']) }}</div></div>
                                <x-status :value="LeaveStatus::from($l['status'])" />
                                @if (in_array($l['status'], ['pending', 'approved'], true) && $l['start_date'] > $today)
                                    <button type="button" class="btn btn-quiet btn-sm" wire:click="cancel({{ $l['id'] }})" wire:confirm="Cancel this leave?">Cancel</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif

    @if ($tab === 'balances')
        <div class="table-wrap">
            <table class="table">
                <caption class="visually-hidden">Annual leave balances for {{ $year }}</caption>
                <thead><tr><th>Person</th><th class="num">Allowance</th><th class="num">Carried over</th><th class="num">Taken</th><th class="num">Booked</th><th class="num">Waiting</th><th class="num">Left</th><th class="num">TOIL</th>@can('leave.manage')<th><span class="visually-hidden">Actions</span></th>@endcan</tr></thead>
                <tbody>
                    @foreach ($balances as $row)
                        @php($b = $row['balance'])
                        <tr wire:key="bal-{{ $row['person']->id }}">
                            <td><a href="{{ route('people.show', $row['person']->id) }}" wire:navigate>{{ $row['person']->first_name }} {{ $row['person']->last_name }}</a></td>
                            <td class="num">{{ Display::days($b->allowed) }}</td>
                            <td class="num">{{ Display::days($b->carriedOver) }}</td>
                            <td class="num">{{ Display::days($b->taken) }}</td>
                            <td class="num">{{ Display::days($b->booked) }}</td>
                            <td class="num">{{ Display::days($b->pending) }}</td>
                            <td @class(['num', 'text-negative' => $b->remaining() < 0])>{{ Display::days($b->remaining()) }}</td>
                            <td class="num">{{ Display::days($b->toilRemaining()) }}</td>
                            @can('leave.manage')
                                <td class="text-end"><button type="button" class="btn btn-quiet btn-sm" wire:click="editAllowance({{ $row['person']->id }})">Change</button></td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="small text-muted mt-2">Leave year {{ $year }}. Up to {{ config('hive.leave.carry_over_max') }} unused days carry into the next year when it opens. The same figures export from Reports.</p>

        <x-modal id="allowance-modal" title="Annual leave allowance">
            <form id="allowance-form" class="row g-3" wire:submit="saveAllowance" novalidate>
                <p class="col-12 mb-0">{{ $allowanceForm['person_id'] ? $workspace->personName((int) $allowanceForm['person_id']) : '' }}, {{ $year }}</p>
                <x-form-field name="days_allowed" model="allowanceForm.days_allowed" label="Allowance (days)" type="number" step="0.5" />
                <x-form-field name="days_carried_over" model="allowanceForm.days_carried_over" label="Carried over (days)" type="number" step="0.5" />
            </form>
            <x-slot:footer>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="allowance-form" class="btn btn-primary" wire:loading.attr="disabled">Save allowance</button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($tab === 'bank-holidays')
        <div class="grid-12">
            <div @class(['table-wrap', 'span-8' => auth()->user()->can('leave.manage'), 'span-12' => ! auth()->user()->can('leave.manage')])>
                <table class="table">
                    <thead><tr><th>Date</th><th>Holiday</th><th>Region</th>@can('leave.manage')<th><span class="visually-hidden">Actions</span></th>@endcan</tr></thead>
                    <tbody>
                        @foreach ($bankHolidays as $b)
                            <tr wire:key="bh-{{ $b->id }}" @class(['text-muted' => $b->region !== $region])>
                                <td class="tnum">{{ Display::date($b->date->toDateString(), true) }}</td>
                                <td>{{ $b->name }}</td>
                                <td>{{ $regions[$b->region] }}</td>
                                @can('leave.manage')
                                    <td class="text-end"><button type="button" class="btn btn-quiet btn-sm" wire:click="removeHoliday({{ $b->id }})" wire:confirm="Remove {{ $b->name }}? Leave already requested keeps its days.">Remove</button></td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @can('leave.manage')
                <section class="card span-4 align-self-start">
                    <div class="card-header"><h2 class="card-title">Add a bank holiday</h2></div>
                    <form class="card-body row g-3" wire:submit="addHoliday" novalidate>
                        <div class="col-12">
                            <label for="bh-region" class="form-label">Region</label>
                            <select id="bh-region" class="form-select" wire:model="holidayForm.region">
                                @foreach ($regions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <x-form-field name="date" model="holidayForm.date" label="Date" type="date" col="col-12" />
                        <x-form-field name="name" model="holidayForm.name" label="Name" col="col-12" />
                        <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Add</button></div>
                    </form>
                </section>
            @endcan
        </div>
        <p class="small text-muted mt-2">You take the bank holidays for {{ $regions[$region] }}. A person’s region is set on their profile.</p>
    @endif
</div>
