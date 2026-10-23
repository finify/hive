@php
    use App\Enums\EventStage;
    $margin = $totals['revenue']->minus($totals['forecast']);
@endphp
<div>
    <x-page-head title="Budgets" :answer="$count.' events. Forecast '.$totals['forecast']->formatShort().' against budgets of '.$totals['budget']->formatShort().', for a margin of '.$margin->formatShort().($totals['revenue']->isZero() ? '' : ' ('.round($margin->minor * 100 / $totals['revenue']->minor, 1).'%)').'.'">
        <x-slot:actions>
            <div class="btn-group" role="group" aria-label="Which events">
                <button type="button" @class(['btn btn-secondary', 'active' => $scope === 'active']) wire:click="$set('scope', 'active')">Active</button>
                <button type="button" @class(['btn btn-secondary', 'active' => $scope === 'all']) wire:click="$set('scope', 'all')">All</button>
            </div>
        </x-slot:actions>
    </x-page-head>

    <div class="table-wrap">
        @if ($rows->isEmpty())
            <x-empty title="No budgets to show">Budgets appear here once an event is confirmed and its first budget version is approved.</x-empty>
        @else
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><x-sort field="start" :sort="$sort" :direction="$direction">Event</x-sort></th>
                        <th>Stage</th>
                        <th class="num">Revenue</th>
                        <th class="num"><x-sort field="budget" :sort="$sort" :direction="$direction">Budget</x-sort></th>
                        <th class="num">Committed</th>
                        <th class="num">Actual</th>
                        <th class="num"><x-sort field="forecast" :sort="$sort" :direction="$direction">Forecast</x-sort></th>
                        <th class="num"><x-sort field="margin" :sort="$sort" :direction="$direction">Margin</x-sort></th>
                        <th><x-sort field="health" :sort="$sort" :direction="$direction">Health</x-sort></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr wire:key="b-{{ $r['event']['id'] }}">
                            <td>
                                <x-event-code :event="$r['event']" />
                                <a href="{{ route('events.show', [$r['event']['code'], 'tab' => 'budget']) }}" class="d-block" wire:navigate>{{ $r['event']['name'] }}</a>
                            </td>
                            <td><x-status :value="EventStage::from($r['event']['stage'])" /></td>
                            <td class="num">{{ $r['f']->revenue->format(false) }}</td>
                            <td class="num">{{ $r['f']->budget->format(false) }}</td>
                            <td class="num">{{ $r['f']->committed->format(false) }}</td>
                            <td class="num">{{ $r['f']->actual->format(false) }}</td>
                            <td @class(['num', 'text-negative fw-medium' => $r['f']->forecast()->greaterThan($r['f']->budget)])>{{ $r['f']->forecast()->format(false) }}</td>
                            <td class="num">{{ $r['f']->marginPercent() ?? '—' }}%</td>
                            <td><x-health :status="$r['f']->health" /></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-medium">
                        <td colspan="2">Total</td>
                        <td class="num">{{ $totals['revenue']->format(false) }}</td>
                        <td class="num">{{ $totals['budget']->format(false) }}</td>
                        <td class="num">{{ $totals['committed']->format(false) }}</td>
                        <td class="num">{{ $totals['actual']->format(false) }}</td>
                        <td class="num">{{ $totals['forecast']->format(false) }}</td>
                        <td class="num">{{ $totals['revenue']->isZero() ? '—' : round($margin->minor * 100 / $totals['revenue']->minor, 1) }}%</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
</div>
