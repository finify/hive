@php
    use App\Support\Money;
    use App\Enums\EventStage;
    $workspace = app(\App\Demo\Workspace::class);
    $viewer = app(\App\Demo\Viewer::class);
    $showMoney = auth()->user()->can('budgets.view_all');
@endphp
<div>
    <x-page-head title="Clients" :answer="$items->count().' clients.'">
        @can('directory.manage')
            <x-slot:actions><a href="{{ route('clients.create') }}" class="btn btn-primary" wire:navigate>Add a client</a></x-slot:actions>
        @endcan
    </x-page-head>
    <div class="filters"><label class="visually-hidden" for="cl-search">Search clients</label><input id="cl-search" type="search" class="form-control is-search" placeholder="Search clients" wire:model.live.debounce.300ms="search"></div>
    <div class="table-wrap">
        @if ($items->isEmpty())
            <x-empty title="No clients match">Every client the agency works for is listed here with its contacts and event history.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Client</th><th>Sector</th><th>Account lead</th><th>Primary contact</th><th class="num">Events</th>@if ($showMoney)<th class="num">Confirmed fees</th>@endif</tr></thead>
                <tbody>
                    @foreach ($items as $c)
                        @php($events = $workspace->events()->where('client_id', $c['id']))
                        @php($primary = collect($c['contacts'])->firstWhere('is_primary', true))
                        <tr>
                            <td class="fw-medium">@can('directory.manage')<a href="{{ route('clients.edit', $c['id']) }}" wire:navigate>{{ $c['name'] }}</a>@else{{ $c['name'] }}@endcan<span class="d-block small text-muted fw-normal">{{ $c['billing_address'] }}</span></td>
                            <td>{{ $c['sector'] }}</td>
                            <td>{{ $workspace->personName($c['account_lead_id']) }}</td>
                            <td>{{ $primary['name'] ?? '—' }}<span class="d-block small text-muted">{{ $primary['role'] ?? '' }}, {{ $primary['email'] ?? '' }}</span></td>
                            <td class="num"><a href="{{ route('events.index', ['client' => $c['id']]) }}" class="link" wire:navigate>{{ $events->count() }}</a></td>
                            @if ($showMoney)
                                <td class="num">{{ Money::of((int) $events->filter(fn ($e) => EventStage::from($e['stage'])->isConfirmedOrLater())->sum('client_fee_minor'))->format(false) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
