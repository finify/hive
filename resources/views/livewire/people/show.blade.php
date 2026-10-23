@php
    use App\Enums\AssignmentStatus;
    use App\Enums\EmploymentType;
    use App\Enums\LeaveStatus;
    use App\Enums\TimesheetStatus;
    use App\Support\DateRange;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $viewer = app(\App\Demo\Viewer::class);
    $name = ($p['preferred_name'] ?: $p['first_name']).' '.$p['last_name'];
    $labels = ['overview' => 'Overview', 'bookings' => 'Bookings', 'timesheets' => 'Timesheets', 'leave' => 'Leave', 'documents' => 'Documents', 'skills' => 'Skills', 'rates' => 'Rates', 'restricted' => 'Restricted'];
@endphp
<div>
    <x-page-head :title="$name">
        <x-slot:crumbs>
            @if ($viewer->canSeeDirectory())
                <a href="{{ route('people.index') }}" wire:navigate>Directory</a> <span aria-hidden="true">/</span>
            @endif
            {{ $p['job_title'] }}
        </x-slot:crumbs>
    </x-page-head>

    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        <x-avatar :name="$name" :id="$p['id']" size="lg" />
        <div>
            <div class="fw-medium">{{ $p['job_title'] }}</div>
            <div class="text-muted">{{ EmploymentType::from($p['employment_type'])->label() }}, {{ $workspace->departmentName($p['department_id']) }}, {{ $p['location_city'] }}</div>
            <div class="text-muted small">{{ $p['email'] }}@if ($p['pronouns']), {{ $p['pronouns'] }}@endif</div>
        </div>
        @if ($expired->isNotEmpty() && ($viewer->isSelf($p['id']) || auth()->user()->canAny(['documents.view', 'documents.view_status'])))
            <div class="callout is-negative ms-lg-auto">
                <i class="bi bi-file-earmark-x" aria-hidden="true"></i>
                <div>{{ $expired->pluck('title')->implode(', ') }} expired. {{ config('hive.documents.expired_mandatory') === 'block' ? 'Bookings can’t be confirmed until it’s renewed.' : 'Bookings show a warning until it’s renewed.' }}</div>
            </div>
        @endif
    </div>

    <nav class="tabs" aria-label="Profile sections">
        @foreach ($tabs as $t)
            <button type="button" @class(['active' => $tab === $t]) wire:click="$set('tab', '{{ $t }}')">
                {{ $labels[$t] }}
                @if ($t === 'rates' || $t === 'restricted')
                    <i class="bi bi-lock small ms-1" aria-label="Restricted"></i>
                @endif
            </button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <div class="grid-12">
            <section class="card span-7">
                <div class="card-header"><h2 class="card-title">Details</h2></div>
                <div class="card-body">
                    <dl class="kv">
                        <dt>Preferred name</dt><dd>{{ $p['preferred_name'] ?: $p['first_name'] }}</dd>
                        <dt>Type</dt><dd>{{ EmploymentType::from($p['employment_type'])->label() }}</dd>
                        <dt>Department</dt><dd>{{ $workspace->departmentName($p['department_id']) }}</dd>
                        <dt>Line manager</dt><dd>{{ $manager ? $manager['first_name'].' '.$manager['last_name'] : '—' }}</dd>
                        <dt>Based in</dt><dd>{{ $p['location_city'] }}, {{ $p['location_country'] }}</dd>
                        <dt>Started</dt><dd>{{ Display::date($p['start_date']) }}</dd>
                        <dt>Languages</dt><dd>{{ implode(', ', $p['languages']) }}</dd>
                        @if (array_key_exists('company_name', $p) && $p['company_name'])
                            <dt>Company</dt><dd>{{ $p['company_name'] }}@if ($p['vat_registered']), VAT {{ $p['vat_number'] }}@endif</dd>
                        @endif
                        @if (array_key_exists('phone', $p))
                            <dt>Phone</dt><dd class="tnum">{{ $p['phone'] }}</dd>
                        @endif
                        @if ($p['rating'])
                            <dt>Producer rating</dt><dd>{{ $p['rating'] }} of 5</dd>
                        @endif
                    </dl>
                </div>
            </section>
            <section class="card span-5">
                <div class="card-header"><h2 class="card-title">Next bookings</h2></div>
                @if ($upcoming->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($upcoming->take(5) as $row)
                            <li>
                                <div class="row-main">
                                    <div class="row-title text-truncate">{{ $row['event']['name'] }}</div>
                                    <div class="row-sub tnum">{{ DateRange::of($row['assignment']['start_date'], $row['assignment']['end_date'])->format() }}</div>
                                </div>
                                <x-status :value="AssignmentStatus::from($row['assignment']['status'])" />
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="Nothing booked ahead">Holds and confirmed bookings on upcoming events appear here.</x-empty>
                @endif
            </section>
        </div>
    @endif

    @if ($tab === 'bookings')
        <div class="table-wrap">
            @if ($upcoming->isEmpty() && $past->isEmpty())
                <x-empty title="No bookings yet">Every hold and booking for {{ $p['first_name'] }} appears here, past and upcoming.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>Event</th><th>Dates</th><th>Status</th>@if ($viewer->canSeeRatesOf($p['id']))<th class="num">Agreed day rate</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($upcoming->merge($past) as $row)
                            <tr>
                                <td>@if ($viewer->canSeeEvent($row['event']))<x-event-code :event="$row['event']" />@else<span class="code">{{ $row['event']['code'] }}</span>@endif {{ $row['event']['name'] }}</td>
                                <td class="tnum">{{ DateRange::of($row['assignment']['start_date'], $row['assignment']['end_date'])->format() }}</td>
                                <td><x-status :value="AssignmentStatus::from($row['assignment']['status'])" /></td>
                                @if ($viewer->canSeeRatesOf($p['id']))
                                    <td class="num">{{ isset($row['assignment']['agreed_rate_minor']) && $row['assignment']['agreed_rate_minor'] ? Money::of($row['assignment']['agreed_rate_minor'])->format() : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @if ($tab === 'timesheets')
        <div class="card">
            @if ($sheets->isEmpty())
                <x-empty title="No timesheets">Weekly timesheets appear here once time is recorded.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($sheets as $sheet)
                        <li>
                            <div class="row-main">
                                <div class="row-title">Week of {{ Display::date($sheet['week_start']) }}</div>
                                <div class="row-sub">{{ count($sheet['entries']) }} entries, {{ Display::hours(collect($sheet['entries'])->sum('hours')) }}</div>
                            </div>
                            <x-status :value="TimesheetStatus::from($sheet['status'])" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($tab === 'leave')
        <div class="card">
            @if ($leave->isEmpty())
                <x-empty title="No leave recorded">Approved and requested leave shows here and on the rota.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($leave as $l)
                        <li>
                            <div class="row-main">
                                <div class="row-title">{{ $viewer->leaveLabel($l) }}</div>
                                <div class="row-sub tnum">{{ DateRange::of($l['start_date'], $l['end_date'])->format() }}, {{ Display::days($l['days']) }}</div>
                            </div>
                            <x-status :value="LeaveStatus::from($l['status'])" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($tab === 'documents')
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Document</th>@if ($canSeeDocumentDetail)<th>Issued</th>@endif<th>Expires</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td>{{ $canSeeDocumentDetail ? $doc['title'] : ucfirst(str_replace('-', ' ', $doc['type'])) }}@if ($doc['mandatory'])<span class="small text-muted d-block">Mandatory</span>@endif</td>
                            @if ($canSeeDocumentDetail)
                                <td class="tnum">{{ Display::date($doc['issued_at']) }}</td>
                            @endif
                            <td class="tnum">{{ Display::date($doc['expires_at']) }}</td>
                            <td><x-status :value="$workspace->documentStatus($doc)" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($tab === 'skills')
        <div class="card">
            @if ($p['skills'] === [])
                <x-empty title="No skills recorded">Skills are rated from 1 to 5 and marked verified once a head of department confirms them.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($p['skills'] as $s)
                        <li>
                            <div class="row-main">{{ $workspace->skills()->get($s['skill_id'])['name'] }}<span class="d-block small text-muted">{{ $workspace->skills()->get($s['skill_id'])['category'] }}</span></div>
                            <div class="meter" style="width: 120px" role="img" aria-label="Level {{ $s['level'] }} of 5"><span style="width: {{ $s['level'] * 20 }}%"></span></div>
                            <span class="tnum small" style="width: 3rem">{{ $s['level'] }} of 5</span>
                            <span class="small text-muted" style="width: 5rem">{{ $s['verified'] ? 'Verified' : 'Self-rated' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($tab === 'rates' && array_key_exists('day_rates', $p))
        <div class="table-wrap">
            @if ($p['day_rates'] === [])
                <x-empty title="No day rates">Freelancers’ day rates are effective-dated. A new rate never changes costs already posted.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>Role</th><th class="num">Day rate</th><th>From</th><th>To</th></tr></thead>
                    <tbody>
                        @foreach ($p['day_rates'] as $rate)
                            <tr>
                                <td>{{ $rate['role_name'] }}</td>
                                <td class="num">{{ Money::of($rate['amount_minor'], $rate['currency'])->format() }}</td>
                                <td class="tnum">{{ Display::date($rate['effective_from']) }}</td>
                                <td class="tnum">{{ $rate['effective_to'] ? Display::date($rate['effective_to']) : 'Current' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @if ($tab === 'restricted' && array_key_exists('restricted', $p))
        <section class="card mw-form">
            <div class="card-header"><h2 class="card-title">Restricted details</h2><span class="small text-muted ms-auto">HR and {{ $p['first_name'] }} only. Encrypted at rest.</span></div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Emergency contact</dt><dd>{{ $p['restricted']['emergency_contact'] ?? '—' }}</dd>
                    <dt>Nationality</dt><dd>{{ $p['restricted']['nationality'] ?? '—' }}</dd>
                    <dt>Passport</dt><dd>{{ $p['restricted']['passport_number'] ? '•••• '.substr($p['restricted']['passport_number'], -3).', expires '.Display::date($p['restricted']['passport_expiry']) : 'Not held' }}</dd>
                    <dt>Dietary needs</dt><dd>{{ $p['restricted']['dietary_requirements'] ?? 'None recorded' }}</dd>
                    <dt>Access needs</dt><dd>{{ $p['restricted']['access_needs'] ?? 'None recorded' }}</dd>
                    <dt>Notes</dt><dd>{{ $p['notes'] ?? '—' }}</dd>
                </dl>
            </div>
        </section>
    @endif
</div>
