@php
    use App\Support\Display;
    $workspace = app(\App\Demo\Workspace::class);
    $fmt = function ($value) {
        if (is_array($value)) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        if (is_bool($value)) { return $value ? 'yes' : 'no'; }
        return $value === null || $value === '' ? '—' : (string) $value;
    };
@endphp
<div>
    <x-page-head title="Audit log" answer="Who changed what, and when, with the values before and after. Newest first." />

    <div class="filters">
        <label class="visually-hidden" for="al-log">Area</label>
        <select id="al-log" class="form-select" wire:model.live="log">
            <option value="">All areas</option>
            @foreach ($logOptions as $l)
                <option value="{{ $l }}">{{ ucfirst($l) }}</option>
            @endforeach
        </select>
        <label class="visually-hidden" for="al-user">User</label>
        <select id="al-user" class="form-select" wire:model.live="causer">
            <option value="">Anyone</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
        </select>
        <label class="small text-muted" for="al-from">From</label>
        <input id="al-from" type="date" class="form-control" wire:model.live="from">
    </div>

    <div class="table-wrap mb-3">
        @if ($entries->isEmpty())
            <x-empty title="Nothing logged for these filters">Sign-ins, approvals, stage changes, bookings and every other change appear here as they happen.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>When</th><th>Who</th><th>Area</th><th>What</th><th>Before</th><th>After</th></tr></thead>
                <tbody>
                    @foreach ($entries as $a)
                        @php($props = $a->properties ?? collect())
                        <tr>
                            <td class="tnum text-nowrap small">{{ Display::dateTime($a->created_at) }}</td>
                            <td>{{ $a->causer?->getAttribute('name') ?? 'System' }}@if ($props->get('ip'))<span class="d-block small text-muted tnum">{{ $props->get('ip') }}</span>@endif</td>
                            <td><x-pill>{{ ucfirst((string) $a->log_name) }}</x-pill></td>
                            <td>{{ $a->description }}@if ($props->get('ref'))<span class="code d-block small text-muted">{{ $props->get('ref') }}</span>@endif</td>
                            <td class="small text-muted">
                                @foreach ((array) $props->get('old', []) as $k => $v)
                                    <div>{{ str_replace('_', ' ', $k) }}: {{ $fmt($v) }}</div>
                                @endforeach
                            </td>
                            <td class="small">
                                @foreach ((array) $props->get('attributes', []) as $k => $v)
                                    <div>{{ str_replace('_', ' ', $k) }}: {{ $fmt($v) }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mb-4">{{ $entries->links() }}</div>

    @if ($seeded->isNotEmpty() && $entries->onFirstPage())
        <section class="card">
            <div class="card-header"><h2 class="card-title">Before this demo session</h2><span class="small text-muted ms-auto">From the demo data</span></div>
            <ul class="list-rows">
                @foreach ($seeded as $s)
                    <li>
                        <span class="small tnum text-muted" style="width: 9rem">{{ Display::dateTime($s['at']) }}</span>
                        <div class="row-main">{{ $s['description'] }}<div class="row-sub">{{ $workspace->personName($s['causer_id']) }}, <span class="code">{{ $s['ref'] }}</span></div></div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
