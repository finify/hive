@php
    use App\Enums\EventStage;
    use App\Support\DateRange;
    use App\Support\Money;
    $workspace = app(\App\Demo\Workspace::class);
    $viewer = app(\App\Demo\Viewer::class);
@endphp
<div>
    <x-page-head title="Events" :answer="$events->total() === $total ? $total.' events you can see.' : $events->total().' of '.$total.' events match.'">
        <x-slot:actions>
            @can('events.view_all')
                <a href="{{ route('events.pipeline') }}" class="btn btn-secondary" wire:navigate><i class="bi bi-kanban me-1" aria-hidden="true"></i>Pipeline</a>
            @endcan
            <a href="{{ route('events.calendar') }}" class="btn btn-secondary" wire:navigate><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Calendar</a>
        </x-slot:actions>
    </x-page-head>

    <div class="filters" role="search">
        <label class="visually-hidden" for="ev-search">Search events</label>
        <input id="ev-search" type="search" class="form-control is-search" placeholder="Search name, code or city" wire:model.live.debounce.300ms="search">

        <label class="visually-hidden" for="ev-stage">Stage</label>
        <select id="ev-stage" class="form-select" wire:model.live="stage">
            <option value="">Any stage</option>
            <option value="active">Active (confirmed to wrap)</option>
            @foreach ($stages as $s)
                <option value="{{ $s->value }}">{{ $s->label() }}</option>
            @endforeach
        </select>

        <label class="visually-hidden" for="ev-client">Client</label>
        <select id="ev-client" class="form-select" wire:model.live="client">
            <option value="">Any client</option>
            @foreach ($clients as $c)
                <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
            @endforeach
        </select>

        <label class="visually-hidden" for="ev-producer">Producer</label>
        <select id="ev-producer" class="form-select" wire:model.live="producer">
            <option value="">Any producer</option>
            @if (auth()->user()->person_id)
                <option value="me">Me</option>
            @endif
            @foreach ($producers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        <label class="visually-hidden" for="ev-country">Country</label>
        <select id="ev-country" class="form-select" wire:model.live="country">
            <option value="">Any country</option>
            @foreach ($countries as $c)
                <option value="{{ $c }}">{{ $c }}</option>
            @endforeach
        </select>

        <label class="small text-muted" for="ev-from">From</label>
        <input id="ev-from" type="date" class="form-control" wire:model.live="from">
        <label class="small text-muted" for="ev-to">to</label>
        <input id="ev-to" type="date" class="form-control" wire:model.live="to">

        @if ($filtered)
            <button type="button" class="btn btn-quiet btn-sm" wire:click="clearFilters">Clear filters</button>
        @endif
    </div>

    <div class="table-wrap" wire:loading.class="opacity-50">
        @if ($events->isEmpty())
            <x-empty title="No events match these filters">Try a wider date range or another stage. New enquiries appear here as soon as a producer adds them.</x-empty>
        @else
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><x-sort field="code" :sort="$sort" :direction="$direction">Code</x-sort></th>
                        <th><x-sort field="name" :sort="$sort" :direction="$direction">Event</x-sort></th>
                        <th><x-sort field="client" :sort="$sort" :direction="$direction">Client</x-sort></th>
                        <th>Producer</th>
                        <th><x-sort field="start" :sort="$sort" :direction="$direction">Dates</x-sort></th>
                        <th><x-sort field="stage" :sort="$sort" :direction="$direction">Stage</x-sort></th>
                        <th>Budget health</th>
                        <th class="num"><x-sort field="fee" :sort="$sort" :direction="$direction">Client fee</x-sort></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        @php($canBudget = $viewer->canSeeBudget($event))
                        <tr wire:key="ev-{{ $event['id'] }}">
                            <td><x-event-code :event="$event" /></td>
                            <td>
                                <a href="{{ route('events.show', $event['code']) }}" class="fw-medium" wire:navigate>{{ $event['name'] }}</a>
                                <div class="small text-muted">{{ $event['city'] }}, {{ $event['country'] }}</div>
                            </td>
                            <td>{{ $workspace->clients()->get($event['client_id'])['name'] ?? '—' }}</td>
                            <td>{{ $workspace->personName($event['producer_id']) }}</td>
                            <td class="tnum text-nowrap">{{ DateRange::of($event['start_date'], $event['end_date'])->format() }}</td>
                            <td><x-status :value="EventStage::from($event['stage'])" /></td>
                            <td>
                                @if ($canBudget)
                                    <x-health :status="$workspace->health($event['id'])" />
                                @else
                                    <span class="text-muted small">Not shared with you</span>
                                @endif
                            </td>
                            <td class="num">{{ $canBudget && $event['client_fee_minor'] ? Money::of($event['client_fee_minor'])->format(false) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-3">{{ $events->links() }}</div>
</div>
