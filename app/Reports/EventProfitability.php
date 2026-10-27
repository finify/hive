<?php

namespace App\Reports;

use App\Enums\EventStage;

class EventProfitability extends Report
{
    public static function key(): string
    {
        return 'event-profitability';
    }

    public function title(): string
    {
        return 'Event profitability';
    }

    public function description(): string
    {
        return 'Budget, forecast and actual cost against revenue, with margin, for each event whose budget you can see.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('budgets.view');
    }

    public function filters(): array
    {
        $stages = ['active' => 'Active events', 'all' => 'All stages'];

        foreach (EventStage::cases() as $s) {
            $stages[$s->value] = $s->label();
        }

        return [
            'stage' => ['label' => 'Stage', 'type' => 'select', 'options' => $stages],
            'from' => ['label' => 'Starting from', 'type' => 'date'],
            'to' => ['label' => 'Starting by', 'type' => 'date'],
        ];
    }

    public function defaults(): array
    {
        return ['stage' => 'all'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Event'], ['key' => 'name', 'label' => 'Name'], ['key' => 'stage', 'label' => 'Stage'],
            ['key' => 'start', 'label' => 'Starts', 'type' => 'date'],
            ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'], ['key' => 'budget', 'label' => 'Budget', 'type' => 'money'],
            ['key' => 'forecast', 'label' => 'Forecast', 'type' => 'money'], ['key' => 'actual', 'label' => 'Actual', 'type' => 'money'],
            ['key' => 'margin', 'label' => 'Margin', 'type' => 'money'], ['key' => 'margin_percent', 'label' => 'Margin %', 'type' => 'percent'],
            ['key' => 'health', 'label' => 'Health'],
        ];
    }

    public function rows(array $filters): array
    {
        $rows = [];

        foreach ($this->viewer->events()->sortBy('start_date') as $e) {
            $stage = EventStage::from($e['stage']);

            if (($filters['stage'] ?? 'all') === 'active' ? $stage->isTerminal() : (($filters['stage'] ?? 'all') !== 'all' && $e['stage'] !== $filters['stage'])) {
                continue;
            }

            if (isset($filters['from']) && (string) $e['start_date'] < $filters['from'] || isset($filters['to']) && (string) $e['start_date'] > $filters['to']) {
                continue;
            }

            $f = $this->viewer->canSeeBudget($e) ? $this->workspace->financials($e['id']) : null;

            if ($f === null) {
                continue;
            }

            $rows[] = [
                'code' => $e['code'], 'name' => $e['name'], 'stage' => $stage->label(), 'start' => $e['start_date'],
                'revenue' => $f->revenue->minor, 'budget' => $f->budget->minor, 'forecast' => $f->forecast()->minor, 'actual' => $f->actual->minor,
                'margin' => $f->margin()->minor, 'margin_percent' => $f->marginPercent(), 'health' => $f->health?->label() ?? 'Final',
            ];
        }

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $sum = fn (string $k) => array_sum(array_column($rows, $k));
        $revenue = $sum('revenue');

        return [
            'revenue' => $revenue, 'budget' => $sum('budget'), 'forecast' => $sum('forecast'), 'actual' => $sum('actual'), 'margin' => $sum('margin'),
            'margin_percent' => $revenue ? round($sum('margin') * 100 / $revenue, 1) : null,
        ];
    }
}
