@php
    use App\Support\Display;
    use App\Support\Money;
    use App\Support\DateRange;
    use App\Enums\EventStage;

    $clashCount = count($clashes);
    $parts = [];
    if ($clashCount) { $parts[] = $clashCount === 1 ? '1 clash' : "{$clashCount} clashes"; }
    $riskCount = collect($atRisk)->where('health', \App\Enums\HealthStatus::AtRisk)->count();
    if ($riskCount) { $parts[] = $riskCount === 1 ? '1 event at risk' : "{$riskCount} events at risk"; }
    if ($pendingEntries) { $parts[] = $pendingEntries === 1 ? '1 timesheet entry to approve' : "{$pendingEntries} timesheet entries to approve"; }
    if ($bookingRequests->count()) { $parts[] = $bookingRequests->count() === 1 ? '1 booking request to answer' : $bookingRequests->count().' booking requests to answer'; }
    if ($invoices->count() && $showFinance) { $parts[] = $invoices->count() === 1 ? '1 invoice to approve' : $invoices->count().' invoices to approve'; }
    $answer = $parts ? ucfirst(implode(', ', array_slice($parts, 0, -1)).(count($parts) > 1 ? ' and ' : '').end($parts)).'.' : 'Nothing needs you right now.';
@endphp

<div>
    <x-page-head :title="$today->format('l j F')" :answer="$answer">
        <x-slot:actions>
            @can('events.create')
                <a href="{{ route('events.pipeline') }}" class="btn btn-secondary" wire:navigate>Pipeline</a>
            @endcan
            @can('rota.view_all')
                <a href="{{ route('rota') }}" class="btn btn-primary" wire:navigate>Open the rota</a>
            @elsecan('timesheets.submit')
                <a href="{{ route('timesheets') }}" class="btn btn-primary" wire:navigate>My timesheet</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    {{-- ============================================================ key figures, by role --}}
    @if ($showLeadership)
        <div class="figure-row">
            <x-figure label="Pipeline (enquiries and proposals)" :value="$pipelineValue->formatShort()" :caption="($pipeline['Enquiry']['count'] + $pipeline['Proposal sent']['count']).' events not yet confirmed'" :href="route('events.pipeline')" />
            <x-figure label="Forecast margin, active events" :value="($margin['percent'] ?? '—').'%'" :caption="$margin['margin']->formatShort().' on '.$margin['revenue']->formatShort().' across '.$margin['events'].' events'" :href="route('budgets')" />
            <x-figure label="Clashes" :value="$clashCount" :caption="$clashCount ? 'Earliest '.Display::date($clashes[0]->dates->start, true) : 'None on the rota'" :href="route('clashes')" />
            <x-figure label="Events at risk or on watch" :value="count($atRisk)" :caption="$riskCount.' at risk, '.(count($atRisk) - $riskCount).' on watch'" :href="route('budgets')" />
        </div>
    @elseif ($showFinance)
        <div class="figure-row">
            <x-figure label="Committed in {{ $today->format('F') }}" :value="$committedThisMonth->formatShort()" caption="Approved POs posted this month" :href="route('budgets')" />
            <x-figure label="Actual in {{ $today->format('F') }}" :value="$actualThisMonth->formatShort()" caption="Approved invoices and crew time" :href="route('budgets')" />
            <x-figure label="Invoices to approve" :value="$invoices->count()" :caption="$invoices->filter(fn ($i) => app(\App\Demo\Workspace::class)->invoiceOverTolerance($i))->count().' over tolerance'" :href="route('supplier-invoices')" />
            <x-figure label="POs awaiting approval" :value="$pendingPos->count()" :caption="Money::sum($pendingPos->map(fn ($p) => Money::of($p['subtotal_minor'], $p['currency'] === 'GBP' ? 'GBP' : 'GBP')))->formatShort().' net'" :href="route('purchase-orders')" />
        </div>
    @elseif ($showHr)
        <div class="figure-row">
            <x-figure label="Starters and leavers, next 30 days" :value="$starters->count() + $leavers->count()" :caption="$starters->map(fn ($p) => $p['first_name'].' '.$p['last_name'].' starts '.Display::shortDate($p['start_date']))->merge($leavers->map(fn ($p) => $p['first_name'].' '.$p['last_name'].' leaves '.Display::shortDate($p['end_date'])))->implode('; ') ?: 'None'" />
            <x-figure label="Documents expiring or expired" :value="$expiring->count()" :caption="$expiring->filter(fn ($d) => app(\App\Demo\Workspace::class)->documentStatus($d)->value === 'expired')->count().' already expired'" :href="route('documents')" />
            <x-figure label="Leave to decide" :value="$leave->count()" caption="Waiting on you" :href="route('approvals', ['tab' => 'leave'])" />
            <x-figure label="Onboarding and offboarding" :value="$checklists->count().' open'" :caption="$checklistItems->isEmpty() ? 'Nothing open' : $checklistItems->whereNotNull('completed_at')->count().' of '.$checklistItems->count().' items done, '.$checklistItems->filter(fn ($i) => $i->completed_at === null && $i->due_date->toDateString() < $today->toDateString())->count().' overdue'" :href="route('onboarding')" />
        </div>
    @elseif ($myEvents->isNotEmpty())
        <div class="figure-row">
            <x-figure label="My events in progress" :value="$myEvents->whereIn('stage', ['live', 'wrap'])->count()" :caption="$myEvents->where('stage', 'live')->pluck('code')->implode(', ') ?: 'None live'" :href="route('events.index', ['producer' => 'me'])" />
            <x-figure label="Clashes on my events" :value="collect($clashes)->filter(fn ($c) => collect($c->eventIds())->intersect($myEvents->keys())->isNotEmpty())->count()" caption="Resolve from the rota" :href="route('clashes')" />
            <x-figure label="Entries to approve" :value="$pendingEntries" caption="On events you produce" :href="route('approvals')" />
            <x-figure label="Open crew calls" :value="$openCrewCalls->count()" :caption="$openCrewCalls->sum(fn ($c) => $c['quantity'] - $c['filled']).' places to fill'" :href="route('crew-calls')" />
        </div>
    @else
        <div class="figure-row">
            <x-figure label="Next booking" :value="$myBookings->first() ? Display::shortDate($myBookings->first()['assignment']['start_date']) : '—'" :caption="$myBookings->first()['event']['name'] ?? 'Nothing booked'" :href="route('my-work')" />
            <x-figure label="Booking requests" :value="$bookingRequests->count()" caption="Waiting for your answer" :href="route('my-work')" />
            <x-figure label="Timesheets" :value="$mySheets->whereIn('status', ['draft', 'returned'])->count()" caption="Draft or returned" :href="route('timesheets')" />
            @if ($leaveBalance)
                <x-figure label="Leave left this year" :value="Display::days($leaveBalance->remaining())" :caption="'of '.Display::days($leaveBalance->entitlement()).($leaveBalance->toilRemaining() > 0 ? ', plus '.Display::days($leaveBalance->toilRemaining()).' TOIL' : '')" :href="route('leave')" />
            @else
                <x-figure label="Documents to update" :value="$myDocuments->count()" caption="Insurance and certificates" :href="route('my-work')" />
            @endif
        </div>
    @endif

    <div class="grid-12">
        {{-- ============================================================ needs attention, everyone --}}
        <section class="card span-7" aria-labelledby="attention-title">
            <div class="card-header">
                <h2 class="card-title" id="attention-title">Needs attention</h2>
                <span class="text-muted small ms-auto">Most urgent first</span>
            </div>
            @if ($attention)
                <ol class="list-rows">
                    @foreach ($attention as $i => $item)
                        <li>
                            <span class="attention-rank">{{ $i + 1 }}</span>
                            <span @class(['flag', 'is-negative' => $item['tone'] === 'negative']) aria-hidden="true"><i class="bi bi-{{ $item['tone'] === 'negative' ? 'exclamation-octagon' : 'exclamation-circle' }}"></i></span>
                            <div class="row-main">
                                <div class="row-title">{{ $item['title'] }}</div>
                                <div class="row-sub">{{ $item['detail'] }}</div>
                            </div>
                            <a href="{{ $item['href'] }}" class="btn btn-secondary btn-sm" wire:navigate>{{ $item['action'] }}</a>
                        </li>
                    @endforeach
                </ol>
            @else
                <x-empty title="You’re clear">Clashes, approvals, booking requests and anything over budget will appear here, most urgent first.</x-empty>
            @endif
        </section>

        {{-- ============================================================ right column by role --}}
        @if ($showLeadership || $showFinance)
            <section class="card span-5" aria-labelledby="risk-title">
                <div class="card-header">
                    <h2 class="card-title" id="risk-title">Budget health</h2>
                    <a href="{{ route('budgets') }}" class="small link ms-auto" wire:navigate>All budgets</a>
                </div>
                @if ($atRisk->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($atRisk as $row)
                            <li class="flex-column align-items-stretch gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <x-event-code :event="$row['event']" />
                                    <span class="text-truncate">{{ $row['event']['name'] }}</span>
                                    <x-status :value="$row['health']" class="ms-auto" />
                                </div>
                                <x-budget-bar :financials="$row['financials']" />
                                <div class="d-flex justify-content-between small text-muted tnum">
                                    <span>Forecast {{ $row['financials']->forecast()->formatShort() }} of {{ $row['financials']->budget->formatShort() }}</span>
                                    <span>Margin {{ $row['financials']->marginPercent() }}%</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="Every event is on track">Events whose forecast goes over 90% of budget, or whose margin drops below target, will appear here.</x-empty>
                @endif
            </section>
        @elseif ($myEvents->isNotEmpty())
            <section class="card span-5" aria-labelledby="my-events-title">
                <div class="card-header">
                    <h2 class="card-title" id="my-events-title">My events</h2>
                    <a href="{{ route('events.index', ['producer' => 'me']) }}" class="small link ms-auto" wire:navigate>All</a>
                </div>
                <ul class="list-rows">
                    @foreach ($myEvents->take(6) as $event)
                        @php($health = app(\App\Demo\Workspace::class)->health($event['id']))
                        <li>
                            <div class="row-main">
                                <div class="row-title text-truncate"><a href="{{ route('events.show', $event['code']) }}" wire:navigate>{{ $event['name'] }}</a></div>
                                <div class="row-sub"><span class="code">{{ $event['code'] }}</span> <span class="ms-1">{{ \App\Support\Display::eventDates($event) }}</span></div>
                            </div>
                            <x-status :value="EventStage::from($event['stage'])" />
                            @if ($health)
                                <x-status :value="$health" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @elseif ($showHr)
            <section class="card span-5" aria-labelledby="docs-title">
                <div class="card-header">
                    <h2 class="card-title" id="docs-title">Documents expiring</h2>
                    <a href="{{ route('documents') }}" class="small link ms-auto" wire:navigate>All documents</a>
                </div>
                <ul class="list-rows">
                    @foreach ($expiring->take(7) as $doc)
                        @php($status = app(\App\Demo\Workspace::class)->documentStatus($doc))
                        <li>
                            <x-avatar :name="app(\App\Demo\Workspace::class)->personName($doc['person_id'])" :id="$doc['person_id']" size="sm" />
                            <div class="row-main">
                                <div class="row-title"><a href="{{ route('people.show', $doc['person_id']) }}" wire:navigate>{{ app(\App\Demo\Workspace::class)->personName($doc['person_id']) }}</a></div>
                                <div class="row-sub">{{ $doc['title'] }}, {{ $status->value === 'expired' ? 'expired' : 'expires' }} {{ Display::date($doc['expires_at']) }}</div>
                            </div>
                            <x-status :value="$status" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            <section class="card span-5" aria-labelledby="bookings-title">
                <div class="card-header">
                    <h2 class="card-title" id="bookings-title">My bookings</h2>
                    <a href="{{ route('my-work') }}" class="small link ms-auto" wire:navigate>My work</a>
                </div>
                @if ($myBookings->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($myBookings->take(6) as $row)
                            <li>
                                <div class="row-main">
                                    <div class="row-title text-truncate">{{ $row['event']['name'] }}</div>
                                    <div class="row-sub">{{ DateRange::of($row['assignment']['start_date'], $row['assignment']['end_date'])->format() }}, {{ $row['event']['city'] }}</div>
                                </div>
                                <x-status :value="\App\Enums\AssignmentStatus::from($row['assignment']['status'])" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="No bookings yet">When a producer books you on an event it appears here, with dates, location and call times.</x-empty>
                @endif
            </section>
        @endif

        {{-- ============================================================ leadership charts --}}
        @if ($showLeadership)
            <section class="card span-7" aria-labelledby="revenue-title">
                <div class="card-header">
                    <h2 class="card-title" id="revenue-title">Confirmed revenue by month</h2>
                    <span class="small text-muted ms-auto">Client fees, by the month each event starts. {{ $today->format('F') }} in blue.</span>
                </div>
                <div class="card-body">
                    <div data-chart="bars" data-chart-data='@json($revenueByMonth + ['format' => 'money'])' style="height: 220px" wire:ignore></div>
                </div>
            </section>
            <section class="card span-5" aria-labelledby="util-title">
                <div class="card-header">
                    <h2 class="card-title" id="util-title">Utilisation by department</h2>
                    <span class="small text-muted ms-auto">Booked days over working days, {{ $today->format('F') }}</span>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled m-0 d-grid gap-3">
                        @foreach ($utilisation as $row)
                            <li>
                                <div class="d-flex justify-content-between small mb-1"><span>{{ $row['label'] }}</span><span class="tnum">{{ round($row['value'] * 100) }}%</span></div>
                                <div class="meter"><span style="width: {{ min(100, round($row['value'] * 100)) }}%"></span></div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
            <section class="card span-12" aria-labelledby="pipeline-title">
                <div class="card-header">
                    <h2 class="card-title" id="pipeline-title">Pipeline value by stage</h2>
                    <a href="{{ route('events.pipeline') }}" class="small link ms-auto" wire:navigate>Pipeline board</a>
                </div>
                <div class="table-wrap border-0 rounded-0">
                    <table class="table">
                        <thead><tr><th>Stage</th><th class="num">Events</th><th class="num">Client fees</th><th style="width: 40%"><span class="visually-hidden">Share</span></th></tr></thead>
                        <tbody>
                            @php($maxStage = max(1, collect($pipeline)->max(fn ($s) => $s['value']->minor)))
                            @foreach ($pipeline as $stage => $row)
                                <tr>
                                    <td>{{ $stage }}</td>
                                    <td class="num">{{ $row['count'] }}</td>
                                    <td class="num">{{ $row['value']->format(false) }}</td>
                                    <td><div class="meter"><span style="width: {{ round($row['value']->minor * 100 / $maxStage) }}%"></span></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- ============================================================ finance lists --}}
        @if ($showFinance && ! $showLeadership)
            <section class="card span-7" aria-labelledby="payments-title">
                <div class="card-header">
                    <h2 class="card-title" id="payments-title">Client payments</h2>
                    <a href="{{ route('billing') }}" class="small link ms-auto" wire:navigate>Client billing</a>
                </div>
                <div class="table-wrap border-0 rounded-0">
                    <table class="table">
                        <thead><tr><th>Event</th><th>Milestone</th><th>Due</th><th class="num">Amount</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($upcomingPayments as $m)
                                @php($event = app(\App\Demo\Workspace::class)->event($m['event_id']))
                                <tr>
                                    <td><x-event-code :event="$event" /></td>
                                    <td>{{ $m['description'] }}</td>
                                    <td class="tnum">{{ Display::date($m['due_date']) }}</td>
                                    <td class="num">{{ Money::of($m['amount_minor'])->format(false) }}</td>
                                    <td><x-status :value="\App\Enums\BillingStatus::from($m['status'])" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        {{-- ============================================================ producer lists --}}
        @if ($myEvents->isNotEmpty() && ! $showLeadership)
            <section class="card span-12" aria-labelledby="calls-title">
                <div class="card-header">
                    <h2 class="card-title" id="calls-title">Open crew calls on my events</h2>
                    <a href="{{ route('crew-calls') }}" class="small link ms-auto" wire:navigate>All crew calls</a>
                </div>
                @if ($openCrewCalls->isNotEmpty())
                    <div class="table-wrap border-0 rounded-0">
                        <table class="table">
                            <thead><tr><th>Event</th><th>Role</th><th>Dates</th><th class="num">Filled</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($openCrewCalls as $call)
                                    <tr>
                                        <td><x-event-code :event="$call['event']" /></td>
                                        <td>{{ $call['role_name'] }}</td>
                                        <td class="tnum">{{ DateRange::of($call['start_date'], $call['end_date'])->format() }}</td>
                                        <td class="num">{{ $call['filled'] }} of {{ $call['quantity'] }}</td>
                                        <td class="text-end"><a href="{{ route('availability', ['role' => $call['role_name'], 'from' => $call['start_date'], 'to' => $call['end_date']]) }}" class="btn btn-secondary btn-sm" wire:navigate>Find people</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-empty title="Every crew call is filled">Crew calls with places still to fill appear here with a shortcut to find available people.</x-empty>
                @endif
            </section>
        @endif

        {{-- ============================================================ own time --}}
        @if (! $showLeadership && ! $showFinance && ! $showHr && $myEvents->isEmpty())
            <section class="card span-12" aria-labelledby="sheets-title">
                <div class="card-header">
                    <h2 class="card-title" id="sheets-title">My timesheets</h2>
                    <a href="{{ route('timesheets') }}" class="small link ms-auto" wire:navigate>All</a>
                </div>
                @if ($mySheets->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($mySheets as $sheet)
                            <li>
                                <div class="row-main">
                                    <div class="row-title">Week of {{ Display::date($sheet['week_start']) }}</div>
                                    <div class="row-sub">{{ count($sheet['entries']) }} {{ count($sheet['entries']) === 1 ? 'entry' : 'entries' }}, {{ Display::hours(collect($sheet['entries'])->sum('hours')) }}</div>
                                </div>
                                <x-status :value="\App\Enums\TimesheetStatus::from($sheet['status'])" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="No timesheets yet" action="Start this week" :href="route('timesheets')">Your weekly timesheets appear here once you start one.</x-empty>
                @endif
            </section>
        @endif
    </div>
</div>
