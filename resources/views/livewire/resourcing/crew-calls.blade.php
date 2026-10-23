@php
    use App\Support\DateRange;
@endphp
<div>
    <x-page-head title="Crew calls" :answer="$calls->count().' '.($show === 'open' ? 'crew calls with places to fill.' : 'upcoming crew calls.')">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Which crew calls">
                <button type="button" @class(['btn btn-secondary', 'active' => $show === 'open']) wire:click="$set('show', 'open')">Open</button>
                <button type="button" @class(['btn btn-secondary', 'active' => $show === 'all']) wire:click="$set('show', 'all')">All upcoming</button>
            </div>
        </x-slot:actions>
    </x-page-head>

    <div class="table-wrap">
        @if ($calls->isEmpty())
            <x-empty title="Every crew call is filled">Requests for roles on upcoming events appear here until they are filled, for example “2 x AV Technician, 4–6 Nov”.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Event</th><th>Role</th><th>Dates</th><th>Call</th><th class="num">Filled</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($calls as $call)
                        <tr>
                            <td><x-event-code :event="$call['event']" /> <span class="d-block small text-muted">{{ $call['event']['name'] }}</span></td>
                            <td>{{ $call['role_name'] }}</td>
                            <td class="tnum text-nowrap">{{ DateRange::of($call['start_date'], $call['end_date'])->format() }}</td>
                            <td class="tnum">{{ $call['call_time'] }}–{{ $call['wrap_time'] }}</td>
                            <td class="num">{{ $call['filled'] }} of {{ $call['quantity'] }}</td>
                            <td><x-pill :tone="$call['filled'] >= $call['quantity'] ? 'positive' : 'warning'">{{ $call['filled'] >= $call['quantity'] ? 'Filled' : ($call['filled'] ? 'Partially filled' : 'Open') }}</x-pill></td>
                            <td class="text-end">
                                @can('crew.manage')
                                    @if ($call['filled'] < $call['quantity'])
                                        <a href="{{ route('availability', ['role' => $call['role_name'], 'from' => $call['start_date'], 'to' => $call['end_date'], 'skill' => $call['skill_id'], 'level' => $call['min_skill_level'] ?? 0]) }}" class="btn btn-secondary btn-sm" wire:navigate>Find people</a>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
