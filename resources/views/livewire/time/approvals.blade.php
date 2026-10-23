@php
    use App\Support\DateRange;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $viewer = app(\App\Demo\Viewer::class);
    $total = $entryCount + $invoices->count() + $leave->count();
@endphp
<div>
    <x-page-head title="Approvals" :answer="$total ? $total.' '.($total === 1 ? 'item waits' : 'items wait').' for you, oldest first. Nothing here is approved until you approve it.' : 'Nothing is waiting for you.'" />

    @if ($coveringFor->isNotEmpty())
        <div class="callout mb-3"><i class="bi bi-people" aria-hidden="true"></i><div>You’re covering approvals for {{ $coveringFor->map(fn ($d) => $workspace->personName($d->person_id).' until '.Display::date($d->ends_on))->implode(' and ') }}. Their items are included below.</div></div>
    @endif

    <nav class="tabs" aria-label="Approval types">
        <button type="button" @class(['active' => $tab === 'all']) wire:click="$set('tab', 'all')">Everything <span class="count">{{ $total }}</span></button>
        @can('timesheets.approve')
            <button type="button" @class(['active' => $tab === 'timesheets']) wire:click="$set('tab', 'timesheets')">Timesheets <span class="count">{{ $entryCount }}</span></button>
        @endcan
        @can('supplier_invoices.approve')
            <button type="button" @class(['active' => $tab === 'invoices']) wire:click="$set('tab', 'invoices')">Supplier invoices <span class="count">{{ $invoices->count() }}</span></button>
        @endcan
        @can('leave.approve')
            <button type="button" @class(['active' => $tab === 'leave']) wire:click="$set('tab', 'leave')">Leave <span class="count">{{ $leave->count() }}</span></button>
        @endcan
    </nav>

    @if ($tab === 'all')
        <div class="grid-12">
            <section class="card span-8">
                <div class="card-header"><h2 class="card-title">Waiting for you, oldest first</h2></div>
                @if ($everything->isEmpty())
                    <x-empty title="Nothing is waiting for you">Timesheets, leave and invoices you approve appear here as they’re submitted, oldest first. You never see your own.</x-empty>
                @else
                    <ul class="list-rows">
                        @foreach ($everything as $row)
                            <li>
                                <div class="row-main">
                                    <div class="row-title">{{ $row['title'] }}</div>
                                    <div class="row-sub">{{ $row['type'] }}@if ($row['detail']), {{ $row['detail'] }}@endif, waiting since {{ Display::date($row['since']) }}@if ($row['for']), for {{ $row['for'] }}@endif</div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" wire:click="$set('tab', '{{ $row['tab'] }}')">Review</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card span-4">
                <div class="card-header"><h2 class="card-title">Cover while you’re away</h2></div>
                @if ($myCover->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($myCover as $d)
                            <li wire:key="cover-{{ $d->id }}">
                                <div class="row-main"><div class="row-title">{{ $workspace->personName($d->delegate_id) }}</div><div class="row-sub tnum">{{ DateRange::of($d->starts_on->toDateString(), $d->ends_on->toDateString())->format() }}</div></div>
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="endCover({{ $d->id }})" wire:confirm="End this cover?">End</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form class="card-body d-grid gap-3" wire:submit="delegate" novalidate>
                    <div>
                        <label for="cv-who" class="form-label">Who approves for you</label>
                        <select id="cv-who" @class(['form-select', 'is-invalid' => $errors->has('cover.delegate_id')]) wire:model="cover.delegate_id">
                            <option value="">Choose…</option>
                            @foreach ($candidates as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('cover.delegate_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label for="cv-from" class="form-label">From</label>
                            <input id="cv-from" type="date" @class(['form-control', 'is-invalid' => $errors->has('cover.starts_on')]) wire:model="cover.starts_on">
                            @error('cover.starts_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label for="cv-to" class="form-label">To</label>
                            <input id="cv-to" type="date" @class(['form-control', 'is-invalid' => $errors->has('cover.ends_on')]) wire:model="cover.ends_on">
                            @error('cover.ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled">Set cover</button>
                    <p class="small text-muted mb-0">They see your items alongside theirs and decide in their own name. Nobody approves their own time or spend.</p>
                </form>
            </section>
        </div>
    @endif

    @if ($tab === 'timesheets')
        @forelse ($groups as $key => $group)
            @php
                $ids = collect($group['items'])->pluck('entry.id')->all();
                $flagged = collect($group['items'])->filter(fn ($i) => $i['flags'] !== []);
                $people = collect($group['items'])->pluck('sheet.person_id')->unique();
                $hours = collect($group['items'])->sum('entry.hours');
                $costs = collect($group['items'])->pluck('cost')->filter();
            @endphp
            <section class="card mb-3" wire:key="grp-{{ $key }}">
                <div class="card-header flex-wrap">
                    <div>
                        <h2 class="card-title">{{ $group['label'] }}</h2>
                        <div class="small text-muted">
                            @if ($group['event'])<span class="code">{{ $group['event']['code'] }}</span>@endif
                            {{ count($ids) }} {{ count($ids) === 1 ? 'entry' : 'entries' }} from {{ $people->map(fn ($id) => $workspace->personName($id))->implode(', ') }}
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary ms-auto" wire:click="approveMany(@js($ids))" wire:loading.attr="disabled">
                        Approve {{ count($ids) }} {{ count($ids) === 1 ? 'entry' : 'entries' }}
                    </button>
                </div>
                <div class="card-body border-bottom border-rule py-2 small">
                    <span class="text-muted">Summary:</span>
                    {{ Display::hours($hours) }} over {{ count($ids) }} {{ count($ids) === 1 ? 'entry' : 'entries' }}@if ($costs->isNotEmpty()), {{ Money::sum($costs)->format() }} of crew cost at the rates effective on those days @endif.
                    @if ($flagged->isNotEmpty())
                        <span class="text-warning-ink">{{ $flagged->count() }} {{ $flagged->count() === 1 ? 'entry is' : 'entries are' }} flagged: {{ $flagged->flatMap(fn ($i) => collect($i['flags'])->pluck('message'))->unique()->implode('; ') }}.</span>
                    @else
                        No working time flags.
                    @endif
                </div>
                <div class="table-wrap border-0 rounded-0">
                    <table class="table">
                        <thead><tr><th>Person</th><th>Date</th><th>Phase</th><th>Times</th><th class="num">Worked</th><th>Flags</th>@if ($costs->isNotEmpty())<th class="num">Cost</th>@endif<th></th></tr></thead>
                        <tbody>
                            @foreach ($group['items'] as $item)
                                @php($entry = $item['entry'])
                                <tr wire:key="entry-{{ $entry['id'] }}">
                                    <td>{{ $workspace->personName($item['sheet']['person_id']) }}@if ($item['delegated_from'])<span class="d-block small text-muted">Covering for {{ $workspace->personName($item['delegated_from']) }}</span>@endif</td>
                                    <td class="tnum text-nowrap">{{ Display::date($entry['date'], true) }}</td>
                                    <td>{{ ucfirst($entry['phase'] ?? '—') }}</td>
                                    <td class="tnum">{{ $entry['start_time'] }}–{{ $entry['end_time'] }}<span class="d-block small text-muted">{{ $entry['break_minutes'] }} min break</span></td>
                                    <td class="num">{{ Display::hours($entry['hours']) }}</td>
                                    <td>
                                        @forelse ($item['flags'] as $flag)
                                            <span @class(['flag d-flex', 'is-negative' => $flag['code'] === 'long-shift'])><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ $flag['message'] }}</span>
                                        @empty
                                            <span class="text-muted small">None</span>
                                        @endforelse
                                        @if ($entry['notes'])
                                            <span class="d-block small text-muted mt-1">“{{ $entry['notes'] }}”</span>
                                        @endif
                                    </td>
                                    @if ($costs->isNotEmpty())
                                        <td class="num">{{ $item['cost']?->format() ?? '—' }}</td>
                                    @endif
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-quiet btn-sm" wire:click="startReturn({{ $entry['id'] }})">Return</button>
                                        <button type="button" class="btn btn-secondary btn-sm" wire:click="approve({{ $entry['id'] }})" wire:loading.attr="disabled">Approve</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <div class="card"><x-empty title="No timesheets to approve">Entries on events you produce, and internal time from people you manage, appear here once submitted. You never see your own.</x-empty></div>
        @endforelse
    @endif

    @if ($tab === 'invoices')
        <div class="table-wrap">
            @if ($invoices->isEmpty())
                <x-empty title="No invoices to approve">Supplier invoices you can approve appear here. Ones over the variance tolerance against their PO are flagged.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>Invoice</th><th>Supplier</th><th>Event</th><th class="num">Net</th><th class="num">Variance</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($invoices as $si)
                            @php($over = $workspace->invoiceOverTolerance($si))
                            <tr>
                                <td class="code">{{ $si['invoice_number'] }}</td>
                                <td>{{ $workspace->suppliers()->get($si['supplier_id'])['name'] }}</td>
                                <td><x-event-code :event="$workspace->event($si['event_id'])" /></td>
                                <td class="num">{{ Money::of($si['net_minor'], $si['currency'])->format() }}</td>
                                <td @class(['num', 'text-negative' => $over])>{{ Money::of($si['variance_minor'], $si['currency'])->format() }}@if ($over)<span class="d-block small">Over tolerance</span>@endif</td>
                                <td class="text-end"><a href="{{ route('supplier-invoices', ['invoice' => $si['id']]) }}" class="btn btn-secondary btn-sm" wire:navigate>Review</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @if ($tab === 'leave')
        <div class="d-grid gap-3">
            @forelse ($leave as $l)
                <section class="card" wire:key="leave-{{ $l['id'] }}">
                    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                        <x-avatar :name="$workspace->personName($l['person_id'])" :id="$l['person_id']" />
                        <div class="flex-grow-1">
                            <div class="fw-medium">{{ $workspace->personName($l['person_id']) }}: {{ $viewer->leaveLabel($l) }}</div>
                            <div class="small text-muted tnum">{{ DateRange::of($l['start_date'], $l['end_date'])->format() }}, {{ Display::days($l['days']) }}@if ($l['reason']), “{{ $l['reason'] }}”@endif</div>
                            @foreach ($l['clashes'] as $a)
                                @php($ev = $workspace->event($a['event_id']))
                                <div class="flag is-negative mt-1"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>Booked on {{ $ev['code'] }} {{ $ev['name'] }}, {{ DateRange::of($a['start_date'], $a['end_date'])->format() }}. Approving creates a clash.</div>
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-quiet" wire:click="decideLeave({{ $l['id'] }}, false)" wire:loading.attr="disabled">Decline</button>
                        <button type="button" class="btn btn-primary" wire:click="decideLeave({{ $l['id'] }}, true)" wire:loading.attr="disabled">{{ $l['clashes'] ? 'Approve anyway' : 'Approve' }}</button>
                    </div>
                </section>
            @empty
                <div class="card"><x-empty title="No leave to decide">Leave requests from people you manage appear here. If the dates overlap a booking you’ll see it before you approve.</x-empty></div>
            @endforelse
        </div>
    @endif

    <x-modal id="return-modal" title="Return entry with a comment">
        <label for="return-comment" class="form-label">What needs changing?</label>
        <textarea id="return-comment" rows="3" @class(['form-control', 'is-invalid' => $errors->has('comment')]) wire:model="returnComment" placeholder="For example: you were booked 08:00 to 19:00, please check your finish time"></textarea>
        @error('comment')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" wire:click="confirmReturn" wire:loading.attr="disabled">Return entry</button>
        </x-slot:footer>
    </x-modal>
</div>
