@php
    use App\Support\Display;
    $access = ['rota' => ['rota.view'], 'approvals' => ['timesheets.approve', 'supplier_invoices.approve', 'leave.approve'], 'supplier-invoices' => ['supplier_invoices.view'], 'people.index' => ['people.view', 'people.view_basic'], 'events.index' => ['events.view'], 'timesheets' => ['timesheets.submit'], 'my-work' => []];
    $routes = ['rota' => 'rota', 'approvals' => 'approvals', 'supplier-invoices' => 'supplier-invoices', 'people' => 'people.index', 'my-work' => 'my-work', 'events' => 'events.index', 'timesheets' => 'timesheets'];
    $icons = ['clash' => 'exclamation-octagon', 'approval' => 'check2-square', 'invoice' => 'file-earmark-text', 'leave' => 'sun', 'document' => 'file-earmark-x', 'booking' => 'calendar-check', 'billing' => 'cash-stack', 'risk' => 'graph-down-arrow', 'po' => 'receipt', 'timesheet' => 'clock'];
    $unread = $notifications->where('read', false)->count();
@endphp
<div>
    <x-page-head title="Notifications" :answer="$unread ? $unread.' unread.' : 'You’re up to date.'">
        <x-slot:actions>
            @if ($unread)
                <button type="button" class="btn btn-secondary" wire:click="markAllRead">Mark all as read</button>
            @endif
            <a href="{{ route('notification-settings') }}" class="btn btn-quiet" wire:navigate>Email settings</a>
        </x-slot:actions>
    </x-page-head>

    <div class="card mw-form">
        @if ($notifications->isEmpty())
            <x-empty title="No notifications">Booking requests, clashes on your events, approvals, expiring documents and stage changes appear here. You choose which ones also come by email.</x-empty>
        @else
            <ul class="list-rows">
                @foreach ($notifications as $n)
                    <li @class(['align-items-start', 'bg-surface' => ! $n['read']])>
                        <i class="bi bi-{{ $icons[$n['type']] ?? 'bell' }} mt-1 {{ $n['read'] ? 'text-muted' : '' }}" aria-hidden="true"></i>
                        <div class="row-main">
                            <div @class(['row-title', 'fw-normal' => $n['read']])>@unless ($n['read'])<span class="visually-hidden">Unread: </span>@endunless{{ $n['title'] }}</div>
                            <div class="row-sub">{{ $n['body'] }}</div>
                            <div class="small text-muted tnum mt-1">{{ Display::dateTime($n['at']) }}</div>
                        </div>
                        @php($route = $routes[$n['link']] ?? null)
                        @if ($route && ($access[$route] === [] || auth()->user()->canAny($access[$route])))
                            <a href="{{ route($route) }}" class="btn btn-quiet btn-sm" wire:navigate>Open</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
