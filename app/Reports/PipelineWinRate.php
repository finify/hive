<?php

namespace App\Reports;

use App\Enums\EventStage;

class PipelineWinRate extends Report
{
    private const WON = ['confirmed', 'pre-production', 'live', 'wrap', 'reconciled', 'closed'];

    public static function key(): string
    {
        return 'pipeline';
    }

    public function title(): string
    {
        return 'Pipeline and win rate';
    }

    public function description(): string
    {
        return 'Events and client fees by stage, and the share of decided pitches won. Fees need budget access.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('events.view_all');
    }

    public function filters(): array
    {
        return [
            'from' => ['label' => 'Events starting from', 'type' => 'date'],
            'to' => ['label' => 'Events starting by', 'type' => 'date'],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'stage', 'label' => 'Stage'], ['key' => 'outcome', 'label' => 'Counts as'],
            ['key' => 'events', 'label' => 'Events', 'type' => 'number'], ['key' => 'value', 'label' => 'Client fees', 'type' => 'money'],
            ['key' => 'share', 'label' => 'Share of decided', 'type' => 'percent'],
        ];
    }

    public function rows(array $filters): array
    {
        $events = $this->viewer->events()->filter(fn ($e) => (! isset($filters['from']) || (string) $e['start_date'] >= $filters['from'])
            && (! isset($filters['to']) || (string) $e['start_date'] <= $filters['to']));
        $decided = $events->filter(fn ($e) => in_array($e['stage'], [...self::WON, 'lost'], true))->count();
        $rows = [];

        foreach (EventStage::cases() as $stage) {
            $in = $events->where('stage', $stage->value);
            $won = in_array($stage->value, self::WON, true);

            $rows[] = [
                'stage' => $stage->label(),
                'outcome' => match (true) {
                    $won => 'Won',
                    $stage === EventStage::Lost => 'Lost',
                    $stage === EventStage::Cancelled => 'Cancelled',
                    default => 'Open',
                },
                'events' => $in->count(),
                'value' => $in->contains(fn ($e) => ! $this->viewer->canSeeBudget($e)) ? null : (int) $in->sum('client_fee_minor'),
                'share' => ($won || $stage === EventStage::Lost) && $decided ? round($in->count() * 100 / $decided, 1) : null,
            ];
        }

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        $won = array_sum(array_column(array_filter($rows, fn ($r) => $r['outcome'] === 'Won'), 'events'));
        $lost = array_sum(array_column(array_filter($rows, fn ($r) => $r['outcome'] === 'Lost'), 'events'));
        $values = array_column($rows, 'value');

        return [
            'outcome' => 'Win rate',
            'events' => array_sum(array_column($rows, 'events')),
            'value' => in_array(null, $values, true) ? null : array_sum($values),
            'share' => $won + $lost ? round($won * 100 / ($won + $lost), 1) : null,
        ];
    }
}
