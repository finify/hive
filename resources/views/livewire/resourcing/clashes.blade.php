@php
    use App\Data\Booking;
    $workspace = app(\App\Demo\Workspace::class);
    $count = count($clashes);
@endphp
<div>
    <x-page-head title="Clashes" :answer="$count === 0 ? 'Nobody is double-booked.' : ($count === 1 ? '1 person is' : $count.' people are').' booked on overlapping dates. Resolve each by moving the days to someone free, or keep both with a reason.'">
        <x-slot:actions>
            <a href="{{ route('rota') }}" class="btn btn-secondary" wire:navigate>Open the rota</a>
        </x-slot:actions>
    </x-page-head>

    <div class="table-wrap">
        @if ($count === 0)
            <x-empty title="No clashes" action="Open the rota" :href="route('rota')">When someone is booked on two events on the same day, or booked over leave, it appears here and is outlined in red on the rota.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Person</th><th>Dates</th><th>Booked on</th><th>Also</th><th></th></tr></thead>
                <tbody>
                    @foreach ($clashes as $clash)
                        <tr wire:key="clash-{{ md5($clash->key()) }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :name="$workspace->personName($clash->personId)" :id="$clash->personId" size="sm" />
                                    <a href="{{ route('people.show', $clash->personId) }}" wire:navigate>{{ $workspace->personName($clash->personId) }}</a>
                                </div>
                            </td>
                            <td class="tnum text-nowrap">{{ $clash->dates->format() }}</td>
                            @foreach ([$clash->first, $clash->second] as $b)
                                <td>
                                    @if ($b->kind === Booking::ASSIGNMENT)
                                        @php($ev = $workspace->event($b->eventId))
                                        <x-event-code :event="$ev" /> {{ $ev['name'] }}
                                        <div class="small text-muted">{{ $ev['city'] }}, producer {{ $workspace->personName($ev['producer_id']) }}</div>
                                    @else
                                        {{ $b->kind === Booking::LEAVE ? app(\App\Demo\Viewer::class)->leaveLabel($workspace->leaveRequests()->get($b->id)) : 'Unavailable' }}
                                        <div class="small text-muted tnum">{{ $b->dates->format() }}</div>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-end">
                                <button type="button" class="btn btn-primary btn-sm" wire:click="$dispatch('resolve-clash', { key: '{{ $clash->key() }}' })">Resolve</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <livewire:resourcing.resolve-clash-modal />
</div>
