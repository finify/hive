@php
    use App\Enums\AssignmentStatus;
    use App\Enums\EntryStatus;
    use App\Enums\EventStage;
    use App\Enums\EventType;
    use App\Enums\PurchaseOrderStatus;
    use App\Enums\SupplierInvoiceStatus;
    use App\Enums\BillingStatus;
    use App\Support\DateRange;
    use App\Support\Display;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $tabLabels = ['overview' => 'Overview', 'tasks' => 'Tasks', 'crew' => 'Crew and resourcing', 'budget' => 'Budget', 'purchase-orders' => 'Purchase orders', 'invoices' => 'Invoices', 'timesheets' => 'Timesheets', 'files' => 'Files', 'activity' => 'Activity'];
    $span = $workspace->eventSpan($event);
@endphp
<div>
    <x-page-head :title="$event['name']">
        <x-slot:crumbs>
            <a href="{{ route('events.index') }}" wire:navigate>Events</a> <span aria-hidden="true">/</span> <span class="code">{{ $event['code'] }}</span>
        </x-slot:crumbs>
        <x-slot:actions>
            @if (in_array($event['stage'], ['live', 'wrap', 'reconciled', 'closed'], true))
                <x-ask-hive-button workflow="wrap-report" :subject="$event['code']" size="md" />
            @endif
            @if ($canEdit)
                <a href="{{ route('events.edit', $event['code']) }}" class="btn btn-secondary" wire:navigate>Edit details</a>
            @endif
            @foreach ($nextStages as $to)
                @if ($to === EventStage::Cancelled || $to === EventStage::Lost)
                    <button type="button" class="btn btn-quiet text-negative" wire:click="prepareStage('{{ $to->value }}')" wire:loading.attr="disabled">{{ $to === EventStage::Cancelled ? 'Cancel event' : 'Mark as lost' }}</button>
                @else
                    <button type="button" class="btn btn-primary" wire:click="prepareStage('{{ $to->value }}')" wire:loading.attr="disabled">Move to {{ $to->label() }}</button>
                @endif
            @endforeach
        </x-slot:actions>
    </x-page-head>

    <div class="gap-row mb-3">
        <x-status :value="$stage" />
        @if ($financials && $financials->health)
            <x-status :value="$financials->health" />
        @endif
        <span class="text-muted">{{ EventType::from($event['event_type'])->label() }}</span>
        <span class="text-muted">{{ $client['name'] ?? '—' }}</span>
        <span class="text-muted">{{ collect([$venue['name'] ?? null, $event['city']])->filter()->implode(', ') ?: 'Venue to be agreed' }}</span>
        <span class="text-muted tnum">{{ \App\Support\Display::eventDates($event) }}</span>
    </div>

    <nav class="tabs" aria-label="Event sections">
        @foreach ($tabs as $t)
            <button type="button" @class(['active' => $tab === $t]) wire:click="$set('tab', '{{ $t }}')" @if ($tab === $t) aria-current="page" @endif>
                {{ $tabLabels[$t] }}
                @if ($t === 'crew' && $clashKeys)
                    <span class="pill is-negative ms-1">Clash</span>
                @endif
            </button>
        @endforeach
    </nav>

    <div wire:loading.class="opacity-50" wire:target="tab">
    {{-- ============================================================ overview --}}
    @if ($tab === 'overview')
        @if ($financials)
            <div class="figure-row">
                <x-figure label="Client fee" :value="$financials->revenue->formatShort()" caption="Revenue lines total" />
                <x-figure label="Forecast at completion" :value="$financials->forecast()->formatShort()" :caption="$financials->forecastPercentOfBudget().'% of '.$financials->budget->formatShort().' budget'" />
                <x-figure label="Margin" :value="($financials->marginPercent() ?? '—').'%'" :caption="$financials->margin()->formatShort().' forecast'" />
                <x-figure label="Actual so far" :value="$financials->actual->formatShort()" :caption="$financials->committed->formatShort().' committed'" />
            </div>
        @endif

        <div class="grid-12">
            <section class="card span-7">
                <div class="card-header"><h2 class="card-title">About this event</h2></div>
                <div class="card-body">
                    <p>{{ $event['description'] }}</p>
                    <dl class="kv">
                        <dt>Client</dt><dd>{{ $client['name'] ?? '—' }}</dd>
                        <dt>Producer</dt><dd>{{ $workspace->personName($event['producer_id']) }}</dd>
                        <dt>Venue</dt><dd>{{ $venue['name'] ?? '—' }}<div class="small text-muted">{{ $venue['address'] ?? '' }}</div></dd>
                        <dt>Attendance</dt><dd class="tnum">{{ $event['expected_attendance'] ? number_format($event['expected_attendance']).' expected' : '—' }}</dd>
                        <dt>Timezone</dt><dd>{{ $event['timezone'] }}</dd>
                        <dt>Contract</dt>
                        <dd>
                            @if ($event['contract_signed'] ?? false)
                                Signed
                            @else
                                <span class="text-warning-ink">Not signed yet</span>
                                @if ($canEdit)
                                    <button type="button" class="btn btn-secondary btn-sm ms-2" wire:click="startContractUpload">Upload signed contract</button>
                                @endif
                            @endif
                        </dd>
                    </dl>
                </div>
            </section>

            <section class="card span-5">
                <div class="card-header"><h2 class="card-title">Phases</h2><span class="small text-muted ms-auto tnum">{{ $span->format() }}</span></div>
                @if ($event['phases'])
                    <ul class="list-rows">
                        @foreach ($event['phases'] as $phase)
                            <li>
                                <span class="fw-medium" style="width: 6rem">{{ \App\Enums\PhaseType::from($phase['type'])->label() }}</span>
                                <span class="tnum text-muted">{{ DateRange::of($phase['start_date'], $phase['end_date'])->format() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="No phases yet">Build, rehearsal, live and breakdown dates appear here once the dates are firm.</x-empty>
                @endif
            </section>

            @php($open = collect($event['tasks'])->where('status', '!=', 'done')->sortBy(fn ($t) => $t['due_date'] ?? '9999')->take(5))
            <section class="card span-7">
                <div class="card-header"><h2 class="card-title">Next tasks</h2><button type="button" class="btn btn-quiet btn-sm ms-auto" wire:click="$set('tab', 'tasks')">{{ collect($event['tasks'])->where('status', 'done')->count() }} of {{ count($event['tasks']) }} done</button></div>
                @if ($open->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($open as $task)
                            <li>
                                <i @class(['bi', 'bi-circle-half text-warning-ink' => $task['status'] === 'in-progress', 'bi-circle text-muted' => $task['status'] === 'to-do']) aria-hidden="true"></i>
                                <div class="row-main">
                                    <div class="row-title">{{ $task['title'] }}</div>
                                    <div class="row-sub">{{ $workspace->personName($task['assignee_id']) }}</div>
                                </div>
                                <span @class(['small tnum', 'text-negative' => $task['due_date'] && $task['due_date'] < $workspace->today()->toDateString(), 'text-muted' => ! $task['due_date'] || $task['due_date'] >= $workspace->today()->toDateString()])>{{ $task['due_date'] ? 'Due '.Display::date($task['due_date']) : 'No due date' }}</span>
                                <span class="visually-hidden">{{ str_replace('-', ' ', $task['status']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($event['tasks'])
                    <x-empty title="Every task is done">Nothing is outstanding on this event.</x-empty>
                @else
                    <x-empty title="No tasks yet">The standard task list for this event type is added when the event is confirmed.</x-empty>
                @endif
            </section>

            <section class="card span-5">
                <div class="card-header"><h2 class="card-title">Team</h2></div>
                @if ($event['team'])
                <ul class="list-rows">
                    @foreach ($event['team'] as $member)
                        <li>
                            <x-avatar :name="$workspace->personName($member['person_id'])" :id="$member['person_id']" size="sm" />
                            <div class="row-main"><a href="{{ route('people.show', $member['person_id']) }}" wire:navigate>{{ $workspace->personName($member['person_id']) }}</a></div>
                            <span class="small text-muted">{{ $member['role'] }}</span>
                        </li>
                    @endforeach
                </ul>
                @else
                    <x-empty title="No team yet">Add the producer, coordinators and leads on the event's details.</x-empty>
                @endif
            </section>
        </div>
    @endif

    {{-- ============================================================ tasks --}}
    @if ($tab === 'tasks')
        <div class="grid-12">
            <section class="card span-8">
                <div class="card-header">
                    <h2 class="card-title">Tasks</h2>
                    <span class="small text-muted ms-auto">{{ $tasks->where('status', 'done')->count() }} of {{ $tasks->count() }} done</span>
                </div>
                @if ($tasks->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($tasks as $task)
                            @php($status = \App\Enums\TaskStatus::from($task['status']))
                            <li wire:key="task-{{ $task['id'] }}">
                                @if ($task['can_update'])
                                    <button type="button" class="icon-btn" wire:click="cycleTask({{ $task['id'] }})" wire:loading.attr="disabled" aria-label="{{ $task['title'] }}: {{ $status->label() }}. Mark as {{ $status->next()->label() }}" title="Mark as {{ $status->next()->label() }}">
                                        <i @class(['bi', 'bi-check-circle-fill text-positive' => $task['status'] === 'done', 'bi-circle-half text-warning-ink' => $task['status'] === 'in-progress', 'bi-circle text-muted' => $task['status'] === 'to-do']) aria-hidden="true"></i>
                                    </button>
                                @else
                                    <i @class(['bi', 'bi-check-circle-fill text-positive' => $task['status'] === 'done', 'bi-circle-half text-warning-ink' => $task['status'] === 'in-progress', 'bi-circle text-muted' => $task['status'] === 'to-do']) aria-hidden="true"></i>
                                @endif
                                <div class="row-main">
                                    <div @class(['row-title', 'text-muted text-decoration-line-through' => $task['status'] === 'done'])>{{ $task['title'] }}</div>
                                    <div class="row-sub">{{ $task['assignee_id'] ? $workspace->personName($task['assignee_id']) : 'Unassigned' }}</div>
                                </div>
                                <x-status :value="$status" />
                                <span @class(['small tnum text-nowrap', 'text-negative' => $task['status'] !== 'done' && $task['due_date'] && $task['due_date'] < $workspace->today()->toDateString(), 'text-muted' => $task['status'] === 'done' || ! $task['due_date'] || $task['due_date'] >= $workspace->today()->toDateString()])>{{ $task['due_date'] ? Display::date($task['due_date']) : 'No due date' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="No tasks yet">
                        The standard list for {{ mb_strtolower(EventType::from($event['event_type'])->label()) }} events is added automatically when the event is confirmed.
                        @if ($canEdit)
                            <div class="mt-2"><button type="button" class="btn btn-secondary btn-sm" wire:click="applyTemplate" wire:loading.attr="disabled">Add the standard list now</button></div>
                        @endif
                    </x-empty>
                    @error('template')<div class="callout is-warning m-3"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>{{ $message }}</div></div>@enderror
                @endif
            </section>

            @if ($canEdit)
                <section class="card span-4">
                    <div class="card-header"><h2 class="card-title">Add a task</h2></div>
                    <form class="card-body row g-3" wire:submit="addTask">
                        <x-form-field name="title" model="newTask.title" label="Task" col="col-12" />
                        <div class="col-12">
                            <label for="t-assignee" class="form-label">Assigned to</label>
                            <select id="t-assignee" @class(['form-select', 'is-invalid' => $errors->has('newTask.assignee_id')]) wire:model="newTask.assignee_id">
                                <option value="">Nobody yet</option>
                                @foreach ($assignees as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('newTask.assignee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <x-form-field name="due_date" model="newTask.due_date" label="Due (optional)" type="date" col="col-12" />
                        <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Add task</button></div>
                    </form>
                </section>
            @endif
        </div>
    @endif

    {{-- ============================================================ crew --}}
    @if ($tab === 'crew')
        <livewire:events.crew :event-id="$event['id']" :book-call="$bookCall" :book-person="$bookPerson" :key="'crew-'.$event['id']" />
    @endif

    {{-- ============================================================ budget --}}
    @if ($tab === 'budget')
        <livewire:events.budget :event-id="$event['id']" :key="'budget-'.$event['id']" />
    @endif

    {{-- ============================================================ purchase orders --}}
    @if ($tab === 'purchase-orders')
        @can('po.create')
            <div class="mb-3"><a href="{{ route('purchase-orders.create', ['event' => $event['id']]) }}" class="btn btn-primary" wire:navigate>Raise a PO</a></div>
        @endcan
        <div class="table-wrap">
            @if ($pos->isEmpty())
                <x-empty title="No purchase orders yet">POs raised against this event’s budget lines appear here with their approval status.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>PO</th><th>Supplier</th><th>Line</th><th class="num">Net</th><th class="num">VAT</th><th class="num">Total</th><th>Status</th><th>Raised by</th></tr></thead>
                    <tbody>
                        @foreach ($pos as $po)
                            <tr>
                                <td><a href="{{ route('purchase-orders.show', $po['code']) }}" class="code" wire:navigate>{{ $po['code'] }}</a></td>
                                <td>{{ $workspace->supplierName($po['supplier_id']) }}</td>
                                <td>{{ $po['lines'][0]['description'] ?? '—' }}</td>
                                <td class="num">{{ Money::of($po['subtotal_minor'], $po['currency'])->format() }}</td>
                                <td class="num">{{ Money::of($po['vat_minor'], $po['currency'])->format() }}</td>
                                <td class="num">{{ Money::of($po['total_minor'], $po['currency'])->format() }}</td>
                                <td><x-status :value="PurchaseOrderStatus::from($po['status'])" /></td>
                                <td>{{ $workspace->personName($po['raised_by']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    {{-- ============================================================ invoices --}}
    @if ($tab === 'invoices')
        <div class="table-wrap">
            @if ($invoices->isEmpty())
                <x-empty title="No supplier invoices yet">Invoices recorded against this event’s POs appear here, with any variance from the PO.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>Invoice</th><th>Supplier</th><th>Against</th><th class="num">Net</th><th class="num">Variance</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($invoices as $si)
                            @php($over = $workspace->invoiceOverTolerance($si))
                            <tr>
                                <td class="code">{{ $si['invoice_number'] }}</td>
                                <td>{{ $workspace->suppliers()->get($si['supplier_id'])['name'] }}</td>
                                <td class="code">{{ $workspace->purchaseOrders()->get($si['purchase_order_id'])['code'] ?? 'No PO' }}</td>
                                <td class="num">{{ Money::of($si['net_minor'], $si['currency'])->format() }}</td>
                                <td @class(['num', 'text-negative' => $over])>{{ $si['variance_minor'] ? Money::of($si['variance_minor'], $si['currency'])->format() : '—' }}</td>
                                <td>
                                    <x-status :value="SupplierInvoiceStatus::from($si['status'])" />
                                    @if ($over && ! in_array($si['status'], ['approved', 'paid'], true))
                                        <x-pill tone="negative">Over tolerance</x-pill>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @can('supplier-invoice.approve', [$si])
                                        <a href="{{ route('supplier-invoices', ['invoice' => $si['id']]) }}" class="btn btn-secondary btn-sm" wire:navigate>Review</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    {{-- ============================================================ timesheets --}}
    @if ($tab === 'timesheets')
        <div class="table-wrap">
            @if ($entries->isEmpty())
                <x-empty title="No time recorded yet">Crew time on this event appears here once people start their timesheets.</x-empty>
            @else
                <table class="table">
                    <thead><tr><th>Date</th><th>Person</th><th>Phase</th><th>Hours</th><th class="num">Worked</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($entries as $row)
                            <tr>
                                <td class="tnum">{{ Display::date($row['entry']['date'], true) }}</td>
                                <td>{{ $workspace->personName($row['sheet']['person_id']) }}</td>
                                <td>{{ ucfirst($row['entry']['phase'] ?? '—') }}</td>
                                <td class="tnum">{{ $row['entry']['start_time'] }}–{{ $row['entry']['end_time'] }}</td>
                                <td class="num">{{ Display::hours($row['entry']['hours']) }}</td>
                                <td>
                                    @if ($row['sheet']['status'] === 'draft')
                                        <x-pill>Draft</x-pill>
                                    @else
                                        <x-status :value="EntryStatus::from($row['entry']['status'])" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    {{-- ============================================================ files --}}
    @if ($tab === 'files')
        <div class="card mb-3">
            @if ($event['files'])
                <ul class="list-rows">
                    @foreach ($event['files'] as $file)
                        <li wire:key="file-{{ $file['id'] }}">
                            <i class="bi bi-file-earmark{{ str_ends_with($file['name'], '.xlsx') ? '-spreadsheet' : '-pdf' }} text-muted" aria-hidden="true"></i>
                            <div class="row-main">
                                <div>{{ $file['name'] }}</div>
                                <div class="row-sub">{{ $file['title'] }}</div>
                            </div>
                            @if (isset($downloads[$file['id']]))
                                <a href="{{ $downloads[$file['id']] }}" class="btn btn-quiet btn-sm">Open file</a>
                            @elseif (! $file['has_file'])
                                <span class="small text-muted">Seeded record, no file</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <x-empty title="No files yet">Briefs, contracts, floor plans and the run of show live here. Files are stored privately and shared with signed links.</x-empty>
            @endif
        </div>

        @if ($canEdit)
            <section class="card mw-form">
                <div class="card-header"><h2 class="card-title">Upload a file</h2><span class="small text-muted ms-auto">PDF, JPG, PNG or Word, up to 10 MB. Stored privately.</span></div>
                <form class="card-body row g-3" wire:submit="uploadFile">
                    <div class="col-sm-6">
                        <label for="f-type" class="form-label">Type</label>
                        <select id="f-type" @class(['form-select', 'is-invalid' => $errors->has('fileType')]) wire:model="fileType">
                            @foreach ($fileTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('fileType')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label for="f-file" class="form-label">File</label>
                        <input id="f-file" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,image/*" @class(['form-control', 'is-invalid' => $errors->has('upload')]) wire:model="upload">
                        @error('upload')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div wire:loading wire:target="upload" class="small text-muted mt-1">Uploading…</div>
                    </div>
                    @if ($fileType === 'contract' && ! $event['contract_signed'])
                        <div class="col-12 small text-muted">Uploading the signed contract lets this event move to Confirmed.</div>
                    @endif
                    <div class="col-12"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Upload file</button></div>
                </form>
            </section>
        @endif
    @endif

    {{-- ============================================================ activity --}}
    @if ($tab === 'activity')
        <div class="grid-12">
            <section class="card span-7">
                <div class="card-header"><h2 class="card-title">Activity</h2></div>
                @if ($activity->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($activity as $a)
                            <li>
                                <div class="row-main">
                                    <div>{{ $a['description'] }}</div>
                                    <div class="row-sub">{{ $a['who'] }}, {{ Display::dateTime($a['at']) }} @if ($a['live'])<x-pill tone="info" class="ms-1">Since the demo reset</x-pill>@endif</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="Nothing logged yet">Every change to this event, its crew and its money is logged here with who made it and when.</x-empty>
                @endif
            </section>
            <section class="card span-5">
                <div class="card-header"><h2 class="card-title">Stage history</h2></div>
                @if ($history->isNotEmpty())
                    <ul class="list-rows">
                        @foreach ($history as $h)
                            <li>
                                <div class="row-main">
                                    <div>{{ $h['from'] ? EventStage::from($h['from'])->label() : 'New' }} to {{ EventStage::from($h['to'])->label() }}</div>
                                    <div class="row-sub">{{ $workspace->personName($h['by']) }}, {{ Display::dateTime($h['at']) }}</div>
                                    @if ($h['note'])
                                        <div class="note small mt-1">{{ $h['note'] }}</div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty title="No stage changes yet">Each move through the pipeline is recorded here with an optional note.</x-empty>
                @endif
            </section>
        </div>
    @endif
    </div>

    <x-modal id="stage-modal" :title="$targetStage ? 'Move to '.EventStage::from($targetStage)->label() : 'Change stage'">
        @if ($targetStage)
            @php($to = EventStage::from($targetStage))
            @if ($stageBlockers)
                <div class="callout is-warning mb-3">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    <div>
                        <div class="fw-medium mb-1">{{ $event['name'] }} can’t move to {{ $to->label() }} yet</div>
                        <ul class="mb-0 ps-3">
                            @foreach ($stageBlockers as $blocker)
                                <li>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @else
                @if ($to === EventStage::Cancelled)
                    <p>Cancelling releases every booking on {{ $event['code'] }}, tells each person, and asks finance to record any cancellation fees. This can’t be undone.</p>
                @elseif ($to === EventStage::Confirmed)
                    <p>Confirming lets first holds on this event become confirmed bookings.</p>
                @else
                    <p>{{ $event['name'] }} moves from {{ $stage->label() }} to {{ $to->label() }}.</p>
                @endif
                <label for="stage-note" class="form-label">Note for the activity log (optional)</label>
                <textarea id="stage-note" class="form-control" rows="2" wire:model="stageNote"></textarea>
            @endif
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ $stageBlockers ? 'Close' : 'Keep as it is' }}</button>
            @if ($targetStage && ! $stageBlockers)
                <button type="button" @class(['btn', 'btn-danger' => in_array($targetStage, ['cancelled', 'lost'], true), 'btn-primary' => ! in_array($targetStage, ['cancelled', 'lost'], true)]) wire:click="changeStage" wire:loading.attr="disabled">
                    {{ $targetStage === 'cancelled' ? 'Cancel event and release bookings' : 'Move to '.EventStage::from($targetStage)->label() }}
                </button>
            @endif
        </x-slot:footer>
    </x-modal>
</div>
