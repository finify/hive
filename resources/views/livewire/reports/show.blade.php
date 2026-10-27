<div>
    <x-page-head :title="$report->title()" :answer="$report->description()">
        <x-slot:crumbs>
            <a href="{{ route('reports') }}" wire:navigate>Reports</a>
        </x-slot:crumbs>
        <x-slot:actions>
            <a href="{{ $csvUrl }}" class="btn btn-secondary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download CSV</a>
        </x-slot:actions>
    </x-page-head>

    @if ($report->filters())
        <form class="card card-body mb-3" wire:submit.prevent>
            <div class="row g-3 align-items-end">
                @foreach ($report->filters() as $name => $filter)
                    <div class="col-sm-6 col-lg-3">
                        <label for="rf-{{ $name }}" class="form-label">{{ $filter['label'] }}</label>
                        @if ($filter['type'] === 'select')
                            <select id="rf-{{ $name }}" class="form-select" wire:model.live="filters.{{ $name }}">
                                @foreach ($filter['options'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($applied[$name] ?? '') === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="rf-{{ $name }}" type="date" class="form-control" wire:model.live.debounce.400ms="filters.{{ $name }}" value="{{ $applied[$name] ?? '' }}">
                        @endif
                    </div>
                @endforeach
            </div>
        </form>
    @endif

    <div class="table-wrap">
        @if ($rows === [])
            <x-empty title="Nothing for these filters">Widen the dates or choose another option.</x-empty>
        @else
            <table class="table">
                <caption class="visually-hidden">{{ $report->title() }}, {{ count($rows) }} rows</caption>
                <thead>
                    <tr>
                        @foreach ($report->columns() as $c)
                            <th @class(['num' => in_array($c['type'] ?? 'text', ['money', 'number', 'percent', 'days'], true)])>{{ $c['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $row)
                        <tr wire:key="row-{{ $i }}">
                            @foreach ($report->columns() as $c)
                                <td @class(['num' => in_array($c['type'] ?? 'text', ['money', 'number', 'percent', 'days'], true), 'text-negative' => ($c['type'] ?? '') === 'money' && ($row[$c['key']] ?? 0) < 0])>{{ $report->format($row[$c['key']] ?? null, $c['type'] ?? 'text') }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($totals)
                    <tfoot>
                        <tr>
                            @foreach ($report->columns() as $c)
                                <th @class(['num' => in_array($c['type'] ?? 'text', ['money', 'number', 'percent', 'days'], true)])>{{ $loop->first ? ($totals[$c['key']] ?? 'Total') : (array_key_exists($c['key'], $totals) ? $report->format($totals[$c['key']], $c['type'] ?? 'text') : '') }}</th>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        @endif
    </div>
</div>
