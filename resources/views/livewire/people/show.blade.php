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
        @if ($canEdit)
            <x-slot:actions>
                <a href="{{ route('people.edit', $p['id']) }}" class="btn btn-secondary" wire:navigate>Edit details</a>
                @can('people.view_restricted')
                    <div class="dropdown">
                        <button class="btn btn-quiet dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Data protection</button>
                        <div class="dropdown-menu dropdown-menu-end p-1">
                            <a class="dropdown-item rounded" href="{{ route('people.export', $p['id']) }}">Export everything Hive holds (ZIP)</a>
                            <button type="button" class="dropdown-item rounded text-negative" x-on:click="$dispatch('modal', { id: 'erase-modal', show: true })">Anonymise…</button>
                        </div>
                    </div>
                @endcan
            </x-slot:actions>
        @endif
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
        <div class="table-wrap mb-3">
            <table class="table">
                <thead><tr><th>Document</th>@if ($canSeeDocumentDetail)<th>Issued</th>@endif<th>Expires</th><th>Status</th><th><span class="visually-hidden">File</span></th></tr></thead>
                <tbody>
                    @foreach ($documents as $doc)
                        <tr>
                            <td>{{ $canSeeDocumentDetail ? $doc['title'] : ucfirst(str_replace('-', ' ', $doc['type'])) }}@if ($doc['mandatory'])<span class="small text-muted d-block">Mandatory</span>@endif</td>
                            @if ($canSeeDocumentDetail)
                                <td class="tnum">{{ Display::date($doc['issued_at']) }}</td>
                            @endif
                            <td class="tnum">{{ Display::date($doc['expires_at']) }}</td>
                            <td><x-status :value="$workspace->documentStatus($doc)" /></td>
                            <td class="text-end">
                                @if (isset($downloads[$doc['id']]))
                                    <a href="{{ $downloads[$doc['id']] }}" class="btn btn-quiet btn-sm">Open file</a>
                                @elseif ($canSeeDocumentDetail && ! ($doc['has_file'] ?? false))
                                    <span class="small text-muted">No file</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($canUpload)
            <section class="card mw-form">
                <div class="card-header"><h2 class="card-title">Upload a document</h2><span class="small text-muted ms-auto">PDF, JPG, PNG or Word, up to 10 MB. Stored privately.</span></div>
                <form class="card-body row g-3" wire:submit="uploadDocument">
                    <div class="col-sm-6">
                        <label for="d-type" class="form-label">Type</label>
                        <select id="d-type" class="form-select" wire:model="newDocument.type">
                            @foreach ($documentTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label for="d-title" class="form-label">Title</label>
                        <input id="d-title" @class(['form-control', 'is-invalid' => $errors->has('newDocument.title')]) wire:model="newDocument.title" placeholder="For example Public liability insurance £5m">
                        @error('newDocument.title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="d-issued" class="form-label">Issued (optional)</label>
                        <input id="d-issued" type="date" class="form-control" wire:model="newDocument.issued_at">
                    </div>
                    <div class="col-sm-6">
                        <label for="d-expires" class="form-label">Expires (optional)</label>
                        <input id="d-expires" type="date" @class(['form-control', 'is-invalid' => $errors->has('newDocument.expires_at')]) wire:model="newDocument.expires_at">
                        @error('newDocument.expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="d-file" class="form-label">File</label>
                        <input id="d-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,image/*" capture="environment" @class(['form-control', 'is-invalid' => $errors->has('upload')]) wire:model="upload">
                        @error('upload')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div wire:loading wire:target="upload" class="small text-muted mt-1">Uploading…</div>
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Upload document</button></div>
                </form>
            </section>
        @endif
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
        <div class="table-wrap mb-3">
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

        @if ($canAddRate)
            <section class="card mw-form">
                <div class="card-header"><h2 class="card-title">Add a new rate</h2><span class="small text-muted ms-auto">The current rate for the role ends the day before.</span></div>
                <form class="card-body row g-3" wire:submit="addRate">
                    <div class="col-sm-5">
                        <label for="nr-role" class="form-label">Role</label>
                        <input id="nr-role" @class(['form-control', 'is-invalid' => $errors->has('newRate.role_name')]) wire:model="newRate.role_name">
                        @error('newRate.role_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-3">
                        <label for="nr-amount" class="form-label">Day rate (£)</label>
                        <input id="nr-amount" inputmode="decimal" @class(['form-control tnum', 'is-invalid' => $errors->has('newRate.amount')]) wire:model="newRate.amount">
                        @error('newRate.amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label for="nr-from" class="form-label">From</label>
                        <input id="nr-from" type="date" @class(['form-control', 'is-invalid' => $errors->has('newRate.effective_from')]) wire:model="newRate.effective_from">
                        @error('newRate.effective_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save new rate</button></div>
                </form>
            </section>
        @endif
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

    @if ($canEdit)
        @can('people.view_restricted')
            <x-modal id="erase-modal" title="Anonymise {{ $name }}">
                @php($eraseBlockers = app(\App\Actions\Gdpr\AnonymisePerson::class)->blockers($p['id']))
                @if ($eraseBlockers)
                    <ul class="mb-0">@foreach ($eraseBlockers as $b)<li>{{ $b }}</li>@endforeach</ul>
                @else
                    <form id="erase-form" wire:submit="anonymise" novalidate>
                        <p>Their name, contact details, restricted details, documents, skills and notices are removed for good, and their sign-in is closed. Timesheets, costs, invoices and expenses stay, under “Former staff member {{ $p['id'] }}”, so the books still add up.</p>
                        <label for="erase-reason" class="form-label">Why (for the audit log)</label>
                        <input id="erase-reason" @class(['form-control', 'is-invalid' => $errors->has('reason')]) wire:model="erasureReason" placeholder="Erasure request ER-0042">
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </form>
                @endif
                <x-slot:footer>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    @unless ($eraseBlockers)
                        <button type="submit" form="erase-form" class="btn btn-primary" wire:loading.attr="disabled">Anonymise</button>
                    @endunless
                </x-slot:footer>
            </x-modal>
        @endcan
    @endif
</div>
