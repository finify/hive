<?php

namespace App\Reports;

use App\Actions\Finance\PostCost;
use App\Support\Money;

class SupplierSpend extends Report
{
    public static function key(): string
    {
        return 'supplier-spend';
    }

    public function title(): string
    {
        return 'Supplier spend and ratings';
    }

    public function description(): string
    {
        return 'Purchase orders raised and invoices received per supplier, in sterling, with their rating and any disputes.';
    }

    public function allowed(): bool
    {
        return $this->viewer->can('po.view') && $this->viewer->can('supplier_invoices.view');
    }

    public function filters(): array
    {
        $categories = ['all' => 'All categories'];

        foreach ($this->workspace->suppliers()->pluck('category')->unique()->sort() as $c) {
            $categories[(string) $c] = ucfirst(str_replace('-', ' ', (string) $c));
        }

        return [
            'from' => ['label' => 'Raised from', 'type' => 'date'],
            'to' => ['label' => 'Raised to', 'type' => 'date'],
            'category' => ['label' => 'Category', 'type' => 'select', 'options' => $categories],
        ];
    }

    public function defaults(): array
    {
        return ['category' => 'all'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Supplier'], ['key' => 'category', 'label' => 'Category'], ['key' => 'rating', 'label' => 'Rating', 'type' => 'number'],
            ['key' => 'pos', 'label' => 'POs', 'type' => 'number'], ['key' => 'ordered', 'label' => 'Ordered', 'type' => 'money'],
            ['key' => 'invoiced', 'label' => 'Invoiced (net)', 'type' => 'money'], ['key' => 'disputes', 'label' => 'Disputes', 'type' => 'number'],
        ];
    }

    public function rows(array $filters): array
    {
        $events = $this->viewer->events()->filter(fn ($e) => $this->viewer->canSeeBudget($e))->keys()->all();
        $in = fn (?string $date) => (! isset($filters['from']) || substr((string) $date, 0, 10) >= $filters['from']) && (! isset($filters['to']) || substr((string) $date, 0, 10) <= $filters['to']);

        $pos = $this->workspace->purchaseOrders()->filter(fn ($p) => in_array($p['event_id'], $events, true) && ! in_array($p['status'], ['draft', 'pending-approval', 'cancelled'], true) && $in($p['approved_at'] ?? $p['sent_at'] ?? null));
        $invoices = $this->workspace->supplierInvoices()->filter(fn ($i) => in_array($i['event_id'], $events, true) && $in($i['invoice_date']));
        $costs = app(PostCost::class);
        $rows = [];

        foreach ($this->workspace->suppliers()->sortBy('name') as $s) {
            if (($filters['category'] ?? 'all') !== 'all' && $s['category'] !== $filters['category']) {
                continue;
            }

            $mine = $pos->where('supplier_id', $s['id']);
            $bills = $invoices->where('supplier_id', $s['id']);

            if ($mine->isEmpty() && $bills->isEmpty()) {
                continue;
            }

            $rows[] = [
                'name' => $s['name'], 'category' => ucfirst(str_replace('-', ' ', (string) $s['category'])), 'rating' => $s['rating'],
                'pos' => $mine->count(),
                'ordered' => (int) $mine->sum(fn ($p) => $costs->toGbp(Money::of($p['subtotal_minor'], $p['currency']), $p['fx_rate'])->minor),
                'invoiced' => (int) $bills->sum(fn ($i) => $costs->toGbp(Money::of($i['net_minor'], $i['currency']), $i['fx_rate'])->minor),
                'disputes' => $bills->where('status', 'disputed')->count(),
            ];
        }

        usort($rows, fn ($a, $b) => $b['ordered'] <=> $a['ordered']);

        return $rows;
    }

    public function totals(array $rows): ?array
    {
        return $rows === [] ? null : ['pos' => array_sum(array_column($rows, 'pos')), 'ordered' => array_sum(array_column($rows, 'ordered')), 'invoiced' => array_sum(array_column($rows, 'invoiced')), 'disputes' => array_sum(array_column($rows, 'disputes'))];
    }
}
