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

    <div class="grid-12">
        <section class="card span-8">
            <div class="card-header"><h2 class="card-title">Who’s off</h2></div>
            @if ($calendar->isEmpty())
                <x-empty title="Nobody is off">Approved and requested leave appears here and on the rota, so producers see it before they book.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($calendar as $l)
                        <li>
                            <x-avatar :name="$workspace->personName($l['person_id'])" :id="$l['person_id']" size="sm" />
                            <div class="row-main">
                                <div class="row-title">{{ $workspace->personName($l['person_id']) }}</div>
                                <div class="row-sub tnum">{{ $viewer->leaveLabel($l) }}, {{ DateRange::of($l['start_date'], $l['end_date'])->format() }}</div>
                            </div>
                            <x-status :value="LeaveStatus::from($l['status'])" />
                            @if (in_array($l['status'], ['pending', 'approved'], true) && $l['start_date'] > $today)
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="cancel({{ $l['id'] }})" wire:confirm="Cancel this leave?">Cancel</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="span-4 d-grid gap-3 align-content-start">
            @if ($balance)
                @php($allowed = $balance['days_allowed'] + $balance['days_carried_over'])
                <x-figure label="My leave left in {{ $balance['leave_year'] }}" :value="Display::days($allowed - $balance['days_taken'])" :caption="$balance['days_taken'].' taken of '.$allowed.' ('.$balance['days_carried_over'].' carried over)'" />
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
                        <div class="col-12 small text-muted">Weekends and bank holidays aren’t counted. Your line manager decides; producers see the dates on the rota once it’s approved.</div>
                    </form>
                </section>
            @endcan
            <section class="card">
                <div class="card-header"><h2 class="card-title">Bank holidays</h2></div>
                <ul class="list-rows">
                    @foreach ($bankHolidays as $b)
                        <li><div class="row-main">{{ $b['name'] }}<div class="row-sub">{{ $b['region'] === 'scotland' ? 'Scotland' : 'England and Wales' }}</div></div><span class="tnum small">{{ Display::date($b['date'], true) }}</span></li>
                    @endforeach
                </ul>
            </section>
        </div>

        @if ($mine->isNotEmpty())
            <section class="card span-12">
                <div class="card-header"><h2 class="card-title">My requests</h2></div>
                <ul class="list-rows">
                    @foreach ($mine as $l)
                        <li>
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
</div>
