<div>
    <x-page-head title="Calendar" :answer="$view === 'week' ? 'This week, '.$days[0]['date']->format('j M').' to '.end($days)['date']->format('j M Y').'.' : $first->format('F Y').'. Live days are filled; build, rehearsal and breakdown are outlined.'">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Calendar view">
                <button type="button" @class(['btn btn-secondary', 'active' => $view === 'month']) wire:click="$set('view', 'month')" aria-pressed="{{ $view === 'month' ? 'true' : 'false' }}">Month</button>
                <button type="button" @class(['btn btn-secondary', 'active' => $view === 'week']) wire:click="$set('view', 'week')" aria-pressed="{{ $view === 'week' ? 'true' : 'false' }}">Week</button>
            </div>
            @if ($view === 'month')
                <div class="btn-group" role="group" aria-label="Change month">
                    <button type="button" class="btn btn-secondary" wire:click="shift(-1)" aria-label="Previous month"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-secondary" wire:click="$set('month', '{{ $today->format('Y-m') }}')">Today</button>
                    <button type="button" class="btn btn-secondary" wire:click="shift(1)" aria-label="Next month"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
            @endif
        </x-slot:actions>
    </x-page-head>

    <div class="calendar" role="grid" aria-label="Events calendar" wire:loading.class="opacity-50">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)
            <div class="cal-head" role="columnheader">{{ $d }}</div>
        @endforeach
        @foreach ($days as $day)
            <div @class(['cal-day', 'is-outside' => $day['outside'], 'is-today' => $day['today']]) role="gridcell" @if ($view === 'week') style="min-height: 320px" @endif>
                <span class="cal-date">{{ $day['date']->format('j') }}</span>
                @foreach ($day['chips'] as $chip)
                    <a href="{{ route('events.show', $chip['event']['code']) }}" wire:navigate @class(['cal-chip', 'is-phase' => $chip['phase']])
                       style="--event: {{ $chip['event']['colour'] }}; --event-tint: color-mix(in srgb, {{ $chip['event']['colour'] }} 16%, var(--hive-surface))"
                       title="{{ $chip['event']['name'] }}">{{ $chip['label'] }}</a>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
