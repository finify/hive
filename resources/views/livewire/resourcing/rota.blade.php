@php
    use App\Support\Display;
    $days = count($dates);
@endphp
<div>
    <x-page-head title="Rota" :answer="($whole ? $peopleCount.' people, ' : 'Your bookings, ').$range->format().'. '.($clashCount ? ($clashCount === 1 ? '1 clash' : $clashCount.' clashes').' in this view, outlined in red.' : 'No clashes in this view.')">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Rota view">
                <button type="button" @class(['btn btn-secondary', 'active' => $view === 'week']) wire:click="$set('view', 'week')" aria-pressed="{{ $view === 'week' ? 'true' : 'false' }}">2 weeks</button>
                <button type="button" @class(['btn btn-secondary', 'active' => $view === 'month']) wire:click="$set('view', 'month')" aria-pressed="{{ $view === 'month' ? 'true' : 'false' }}">Month</button>
            </div>
            <div class="btn-group" role="group" aria-label="Move through dates">
                <button type="button" class="btn btn-secondary" wire:click="shift(-1)" aria-label="Earlier"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-secondary" wire:click="goToToday">Today</button>
                <button type="button" class="btn btn-secondary" wire:click="shift(1)" aria-label="Later"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
        </x-slot:actions>
    </x-page-head>

    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        @if ($whole)
            <div class="filters mb-0">
                <label class="visually-hidden" for="rota-dept">Department</label>
                <select id="rota-dept" class="form-select" wire:model.live="department">
                    <option value="">All departments</option>
                    @foreach ($departments as $d)
                        <option value="{{ $d['id'] }}">{{ $d['name'] }}</option>
                    @endforeach
                </select>
                <label class="visually-hidden" for="rota-type">Type</label>
                <select id="rota-type" class="form-select" wire:model.live="type">
                    <option value="">Staff and freelance</option>
                    <option value="staff">Staff only</option>
                    <option value="freelance">Freelance only</option>
                </select>
                <label class="visually-hidden" for="rota-skill">Skill</label>
                <select id="rota-skill" class="form-select" wire:model.live="skill">
                    <option value="">Any skill</option>
                    @foreach ($skills as $s)
                        <option value="{{ $s['id'] }}">{{ $s['name'] }}</option>
                    @endforeach
                </select>
                <div class="form-check ms-1">
                    <input class="form-check-input" type="checkbox" id="rota-booked" wire:model.live="bookedOnly">
                    <label class="form-check-label" for="rota-booked">Only people with bookings</label>
                </div>
            </div>
        @endif
        <div class="legend rota-legend ms-auto">
            <span><span class="swatch k-confirmed"></span>Confirmed</span>
            <span><span class="swatch k-first-hold"></span>First hold</span>
            <span><span class="swatch k-second-hold"></span>Second hold</span>
            <span><span class="swatch k-leave"></span>Leave</span>
            <span><span class="swatch k-absent"></span>Absent</span>
            <span><span class="swatch k-unavailable"></span>Unavailable</span>
            <span><span class="swatch is-clash"></span>Clash</span>
        </div>
    </div>

    <div @class(['rota', 'is-month' => $view === 'month']) style="--days: {{ $days }}" wire:loading.class="opacity-50">
        <div class="rota-row rota-head" style="grid-template-rows: 44px">
            <div class="rota-corner">Person</div>
            @foreach ($dates as $date)
                <div @class(['is-weekend' => $date->isWeekend(), 'is-today' => $date->toDateString() === $today]) @if ($date->toDateString() === $today) aria-current="date" @endif>
                    <span>{{ $date->format($view === 'month' ? 'D' : 'D') }}</span>
                    <b>{{ $date->format('j') }}</b>
                </div>
            @endforeach
        </div>

        @forelse ($groups as $department => $rows)
            <div class="rota-group">{{ $department }}</div>
            @foreach ($rows as $row)
                @php($p = $row['person'])
                @php($name = ($p['preferred_name'] ?: $p['first_name']).' '.$p['last_name'])
                <div class="rota-row" style="grid-template-rows: repeat({{ $row['lanes'] }}, 30px)" wire:key="row-{{ $p['id'] }}">
                    <div class="rota-name" style="grid-row: 1 / -1">
                        <x-avatar :name="$name" :id="$p['id']" size="sm" />
                        <span class="who">
                            <a href="{{ route('people.show', $p['id']) }}" class="who-name" wire:navigate>{{ $name }}</a>
                            <span class="who-sub">{{ $p['job_title'] }}@if ($p['employment_type'] === 'freelancer'), freelance @endif</span>
                        </span>
                        <span class="util" title="Booked days over working days in this view">{{ round($row['utilisation'] * 100) }}%</span>
                    </div>
                    @foreach ($dates as $i => $date)
                        <div @class(['rota-cell', 'is-weekend' => $date->isWeekend(), 'is-today' => $date->toDateString() === $today]) style="grid-column: {{ $i + 2 }}; grid-row: 1 / -1"></div>
                    @endforeach
                    @foreach ($row['bars'] as $bar)
                        @php($style = 'grid-column: '.($bar['col'] + 2).' / span '.$bar['span'].'; grid-row: '.$bar['lane'].';')
                        @php($style .= $bar['event'] ? ' --event: '.$bar['event']['colour'].'; --event-tint: color-mix(in srgb, '.$bar['event']['colour'].' 18%, var(--hive-surface));' : '')
                        @php($label = $bar['span'] * ($view === 'month' ? 30 : 44) < 60 ? $bar['label'] : $bar['label'].($bar['event'] && $bar['span'] > 2 ? ' '.$bar['event']['name'] : ''))
                        @if ($bar['clash'])
                            <button type="button" class="rota-bar k-{{ $bar['kind'] }} is-clash" style="{{ $style }}"
                                    wire:click="$dispatch('resolve-clash', { key: '{{ $bar['clash'] }}' })"
                                    title="Clash: {{ $bar['title'] }}, {{ $bar['dates']->format() }}. Select to resolve."
                                    aria-label="Clash for {{ $name }}: {{ $bar['title'] }}, {{ $bar['dates']->format() }}. Resolve">
                                <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>{{ $label }}
                            </button>
                        @elseif ($bar['event'])
                            <a href="{{ route('events.show', [$bar['event']['code'], 'tab' => 'crew']) }}" class="rota-bar k-{{ $bar['kind'] }}" style="{{ $style }}" wire:navigate
                               title="{{ $bar['title'] }}, {{ $bar['dates']->format() }}" aria-label="{{ $name }}: {{ $bar['title'] }}, {{ $bar['dates']->format() }}">{{ $label }}</a>
                        @else
                            <span class="rota-bar k-{{ $bar['kind'] }}" style="{{ $style }}" title="{{ $bar['title'] }}, {{ $bar['dates']->format() }}" aria-label="{{ $name }}: {{ $bar['title'] }}, {{ $bar['dates']->format() }}">{{ $label }}</span>
                        @endif
                    @endforeach
                </div>
            @endforeach
        @empty
            <x-empty title="Nobody matches these filters">Clear a filter to see more people. Bookings, leave and unavailability show here as soon as they are added.</x-empty>
        @endforelse
    </div>

    <livewire:resourcing.resolve-clash-modal />
</div>
