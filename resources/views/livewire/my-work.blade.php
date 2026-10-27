@php
    use App\Enums\AssignmentStatus;
    use App\Support\DateRange;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-page-head title="My work" :answer="($requests->count() ? $requests->count().' booking '.($requests->count() === 1 ? 'request' : 'requests').' to answer. ' : '').$upcoming->count().' upcoming '.($upcoming->count() === 1 ? 'booking' : 'bookings').'.'" />

    @foreach ($clashes as $clash)
        <div class="callout is-negative mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>You’re booked on two things on {{ $clash->dates->format() }}: {{ $clash->first->label }} and {{ $clash->second->label }}. The producers have been told and will sort it out.</div>
        </div>
    @endforeach

    @if ($requests->isNotEmpty())
        <h2 class="fs-6 fw-medium mb-2">Booking requests</h2>
        <div class="d-grid gap-3 mb-4">
            @foreach ($requests as $r)
                <section class="card" wire:key="req-{{ $r['assignment']['id'] }}">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 align-items-baseline mb-1">
                            <span class="code">{{ $r['event']['code'] }}</span>
                            <h3 class="fs-6 fw-medium m-0">{{ $r['event']['name'] }}</h3>
                            <x-status :value="AssignmentStatus::from($r['assignment']['status'])" class="ms-auto" />
                        </div>
                        <dl class="kv small mb-3">
                            <dt>Role</dt><dd>{{ $r['call']['role_name'] ?? '—' }}</dd>
                            <dt>Dates</dt><dd class="tnum">{{ DateRange::of($r['assignment']['start_date'], $r['assignment']['end_date'])->format() }}</dd>
                            <dt>Where</dt><dd>{{ $workspace->venues()->get($r['event']['venue_id'])['name'] }}, {{ $r['event']['city'] }}</dd>
                            <dt>Call</dt><dd class="tnum">{{ $r['call']['call_time'] ?? '' }} to {{ $r['call']['wrap_time'] ?? '' }}</dd>
                            <dt>Day rate</dt><dd class="tnum">{{ $r['assignment']['agreed_rate_minor'] ? Money::of($r['assignment']['agreed_rate_minor'])->format() : '—' }}</dd>
                            <dt>Producer</dt><dd>{{ $workspace->personName($r['event']['producer_id']) }}</dd>
                        </dl>
                        @if ($r['call']['notes'] ?? null)
                            <p class="note small">{{ $r['call']['notes'] }}</p>
                        @endif
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary flex-grow-1 flex-sm-grow-0" wire:click="respond({{ $r['assignment']['id'] }}, true)" wire:loading.attr="disabled">Accept booking</button>
                            <button type="button" class="btn btn-secondary flex-grow-1 flex-sm-grow-0" wire:click="respond({{ $r['assignment']['id'] }}, false)" wire:loading.attr="disabled" wire:confirm="Decline {{ $r['event']['code'] }}? The producer will look for someone else.">Decline</button>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    <div class="grid-12">
        <section class="card span-7">
            <div class="card-header"><h2 class="card-title">Upcoming bookings</h2></div>
            @if ($upcoming->isEmpty())
                <x-empty title="Nothing booked yet">When a producer books you, the event, dates, call times and location appear here.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($upcoming as $r)
                        <li class="flex-wrap">
                            <div class="row-main">
                                <div class="row-title">{{ $r['event']['name'] }}</div>
                                <div class="row-sub tnum">{{ DateRange::of($r['assignment']['start_date'], $r['assignment']['end_date'])->format() }}, {{ $r['event']['city'] }}, {{ $r['call']['role_name'] ?? '' }}, call {{ $r['call']['call_time'] ?? '' }}</div>
                            </div>
                            <x-status :value="AssignmentStatus::from($r['assignment']['status'])" />
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($released->isNotEmpty())
                <div class="card-body border-top border-rule small text-muted">
                    Released or declined: {{ $released->map(fn ($r) => $r['event']['code'].' ('.DateRange::of($r['assignment']['start_date'], $r['assignment']['end_date'])->format().')')->implode(', ') }}
                </div>
            @endif
        </section>

        <div class="span-5 d-grid gap-3 align-content-start">
            @if ($travel->isNotEmpty())
                <section class="card">
                    <div class="card-header"><h2 class="card-title">My travel</h2></div>
                    <ul class="list-rows">
                        @foreach ($travel as $t)
                            <li wire:key="trv-{{ $t->id }}">
                                <i class="bi bi-{{ $t->mode->icon() }}" aria-hidden="true"></i>
                                <div class="row-main">
                                    <div class="row-title">{{ $t->summary() }}</div>
                                    <div class="row-sub">{{ app(\App\Demo\Workspace::class)->event($t->event_id)['code'] ?? '' }}{{ $t->reference ? ' · Ref '.$t->reference : '' }}{{ $t->notes ? ' · '.$t->notes : '' }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <section class="card">
                <div class="card-header"><h2 class="card-title">My documents</h2></div>
                @if ($documents->isEmpty())
                    <x-empty title="No documents on file">Upload your insurance and certificates so producers can confirm you without delay.</x-empty>
                @else
                    <ul class="list-rows">
                        @foreach ($documents as $doc)
                            <li>
                                <div class="row-main">{{ $doc['title'] }}<div class="row-sub">{{ $doc['expires_at'] ? 'Expires '.Display::date($doc['expires_at']) : 'No expiry' }}</div></div>
                                <x-status :value="$workspace->documentStatus($doc)" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            @if ($isFreelancer)
                <section class="card">
                    <div class="card-header"><h2 class="card-title">When you can’t work</h2></div>
                    @if ($unavailable->isNotEmpty())
                        <ul class="list-rows">
                            @foreach ($unavailable as $u)
                                <li wire:key="un-{{ $u['id'] }}">
                                    <div class="row-main"><div class="tnum">{{ DateRange::of($u['start_date'], $u['end_date'])->format() }}</div>@if ($u['note'])<div class="row-sub">{{ $u['note'] }}</div>@endif</div>
                                    <button type="button" class="btn btn-quiet btn-sm" wire:click="removeUnavailable({{ $u['id'] }})" aria-label="Remove {{ DateRange::of($u['start_date'], $u['end_date'])->format() }}">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form class="card-body row g-2" wire:submit="addUnavailable" novalidate>
                        <x-form-field name="start_date" model="unavailableForm.start_date" label="From" type="date" col="col-6" />
                        <x-form-field name="end_date" model="unavailableForm.end_date" label="To" type="date" col="col-6" />
                        <x-form-field name="note" model="unavailableForm.note" label="Note (optional)" col="col-12" placeholder="For example Booked elsewhere" />
                        <div class="col-12"><button type="submit" class="btn btn-secondary" wire:loading.attr="disabled">Mark unavailable</button></div>
                        <div class="col-12 small text-muted">Producers won’t be offered you on these dates.</div>
                    </form>
                </section>
            @elseif ($unavailable->isNotEmpty())
                <section class="card">
                    <div class="card-header"><h2 class="card-title">Marked unavailable</h2></div>
                    <ul class="list-rows">
                        @foreach ($unavailable as $u)
                            <li><div class="row-main tnum">{{ DateRange::of($u['start_date'], $u['end_date'])->format() }}</div><span class="small text-muted">{{ $u['note'] }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
