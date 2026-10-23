@php
    use App\Support\DateRange;
    use App\Support\Money;
@endphp
<div>
    <x-page-head title="Pipeline board" :answer="collect($columns)->sum(fn ($c) => $c['events']->count()).' events from enquiry to confirmed. Win rate '.($winRate ?? '—').'% this year. Drag a card to move it; the stage rules still apply.'">
        <x-slot:actions>
            <a href="{{ route('events.index') }}" class="btn btn-secondary" wire:navigate>Events list</a>
            @can('events.create')
                <a href="{{ route('events.create') }}" class="btn btn-primary" wire:navigate>Add enquiry</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    <div class="board" x-data="{ dragging: null, over: null }">
        @foreach ($columns as $column)
            <section class="board-col"
                     :class="{ 'is-over': over === '{{ $column['stage']->value }}' }"
                     @dragover.prevent="over = '{{ $column['stage']->value }}'"
                     @dragleave="over = null"
                     @drop.prevent="over = null; if (dragging) { $wire.move(dragging, '{{ $column['stage']->value }}'); dragging = null }"
                     aria-labelledby="col-{{ $column['stage']->value }}">
                <div class="board-col-head">
                    <h2 class="fs-6 fw-medium m-0" id="col-{{ $column['stage']->value }}">{{ $column['stage']->label() }} <span class="text-muted tnum">{{ $column['events']->count() }}</span></h2>
                    <span class="value">{{ $column['value']->formatShort() }}</span>
                </div>
                @forelse ($column['events'] as $event)
                    <div class="board-card" draggable="true" wire:key="card-{{ $event['id'] }}"
                         @dragstart="dragging = '{{ $event['code'] }}'; $el.classList.add('is-dragging')"
                         @dragend="$el.classList.remove('is-dragging')">
                        <div class="d-flex justify-content-between align-items-center">
                            <x-event-code :event="$event" />
                            <span class="small tnum text-muted">{{ $event['client_fee_minor'] ? Money::of($event['client_fee_minor'])->formatShort() : 'No budget yet' }}</span>
                        </div>
                        <div class="board-card-title">{{ $event['name'] }}</div>
                        <div class="board-card-meta">{{ $workspace->clients()->get($event['client_id'])['name'] }}</div>
                        <div class="board-card-meta tnum">{{ \App\Support\Display::eventDates($event) }}, {{ $event['city'] }}</div>
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <x-avatar :name="$workspace->personName($event['producer_id'])" :id="$event['producer_id']" size="sm" />
                            <span class="small text-muted">{{ $workspace->personName($event['producer_id']) }}</span>
                            @unless ($event['contract_signed'] ?? false)
                                <span class="small text-muted ms-auto">Contract not signed</span>
                            @endunless
                        </div>
                        <label class="visually-hidden" for="move-{{ $event['id'] }}">Move {{ $event['code'] }} to</label>
                        <select id="move-{{ $event['id'] }}" class="form-select form-select-sm mt-2 d-md-none" wire:change="move('{{ $event['code'] }}', $event.target.value)">
                            <option value="">Move to…</option>
                            @foreach (\App\Enums\EventStage::from($event['stage'])->allowedTransitions() as $to)
                                <option value="{{ $to->value }}">{{ $to->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                @empty
                    <p class="small text-muted">No events at this stage. Drag one here when it moves on.</p>
                @endforelse
            </section>
        @endforeach
    </div>

    @if ($lost->isNotEmpty())
        <section class="card mt-3">
            <div class="card-header"><h2 class="card-title">Lost this year</h2></div>
            <ul class="list-rows">
                @foreach ($lost as $event)
                    <li>
                        <x-event-code :event="$event" />
                        <div class="row-main">{{ $event['name'] }}<div class="row-sub">{{ collect($event['stage_history'])->last()['note'] ?? '' }}</div></div>
                        <span class="tnum text-muted">{{ $event['client_fee_minor'] ? Money::of($event['client_fee_minor'])->formatShort() : '' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-modal id="blocked-modal" :title="$blockedEvent ?: 'Can’t move this event yet'">
        <p class="mb-2">Fix these first:</p>
        <ul class="mb-0 ps-3">
            @foreach ($blockers as $blocker)
                <li>{{ $blocker }}</li>
            @endforeach
        </ul>
        <x-slot:footer>
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
        </x-slot:footer>
    </x-modal>
</div>
