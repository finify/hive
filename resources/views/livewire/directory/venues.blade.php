@php
    $workspace = app(\App\Demo\Workspace::class);
@endphp
<div>
    <x-page-head title="Venues" :answer="$items->count().' venues in the UK and Europe.'">
        @can('directory.manage')
            <x-slot:actions><a href="{{ route('venues.create') }}" class="btn btn-primary" wire:navigate>Add a venue</a></x-slot:actions>
        @endcan
    </x-page-head>
    <div class="filters"><label class="visually-hidden" for="vn-search">Search venues</label><input id="vn-search" type="search" class="form-control is-search" placeholder="Search venues" wire:model.live.debounce.300ms="search"></div>
    <div class="d-grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr))">
        @forelse ($items as $v)
            <section class="card">
                <div class="card-header"><h2 class="card-title">@can('directory.manage')<a href="{{ route('venues.edit', $v['id']) }}" wire:navigate>{{ $v['name'] }}</a>@else{{ $v['name'] }}@endcan</h2><span class="small text-muted ms-auto tnum">{{ number_format($v['capacity']) }} capacity</span></div>
                <div class="card-body small">
                    <p class="text-muted mb-2">{{ $v['address'] }}</p>
                    <dl class="kv">
                        <dt>Access</dt><dd>{{ $v['access_notes'] }}</dd>
                        <dt>Power and rigging</dt><dd>{{ $v['power_notes'] }}</dd>
                        <dt>Events here</dt><dd>{{ $workspace->events()->where('venue_id', $v['id'])->pluck('code')->implode(', ') ?: 'None yet' }}</dd>
                    </dl>
                </div>
            </section>
        @empty
            <div class="card"><x-empty title="No venues match">Venues hold access, power and rigging notes, floor plans and every event held there.</x-empty></div>
        @endforelse
    </div>
</div>
