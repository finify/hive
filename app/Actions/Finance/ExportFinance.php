<?php

namespace App\Actions\Finance;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\EntryStatus;
use App\Enums\ExpenseStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;

/**
 * CSV exports for the accounting system and payment runs (section 5.7).
 * Each export is logged. Freelancer costs carry day rates, so they need
 * rates.view (rule 17).
 */
class ExportFinance
{
    public const TYPES = [
        'supplier-invoices' => 'Approved supplier invoices',
        'freelancer-costs' => 'Approved freelancer time, for the payment run',
        'expenses' => 'Approved expenses',
        'billing' => 'Client billing',
    ];

    public function __construct(private readonly Workspace $workspace) {}

    public function can(User $user, string $type): bool
    {
        if (! $user->can('billing.view') || ! isset(self::TYPES[$type])) {
            return false;
        }

        return $type !== 'freelancer-costs' || $user->can('rates.view');
    }

    /**
     * @return array{filename: string, rows: list<list<string|int|null>>}
     */
    public function build(User $user, string $type, string $from, string $to): array
    {
        abort_unless($this->can($user, $type), 403);
        Validator::make(['from' => $from, 'to' => $to], ['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']])->validate();

        $rows = match ($type) {
            'supplier-invoices' => $this->supplierInvoices($from, $to),
            'freelancer-costs' => $this->freelancerCosts($from, $to),
            'expenses' => $this->expenses($from, $to),
            default => $this->billing($from, $to),
        };

        activity('finance')
            ->causedBy($user)
            ->withProperties(['ref' => 'EXPORT', 'attributes' => ['type' => $type, 'from' => $from, 'to' => $to, 'rows' => count($rows) - 1]])
            ->event('exported')
            ->log('Exported '.mb_strtolower(self::TYPES[$type])." for {$from} to {$to}");

        return ['filename' => "hive-{$type}-{$from}-to-{$to}.csv", 'rows' => $rows];
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function supplierInvoices(string $from, string $to): array
    {
        $rows = [['Invoice', 'Supplier', 'Supplier VAT number', 'Event', 'PO', 'Nominal code', 'Invoice date', 'Due date', 'Currency', 'Net', 'VAT', 'Gross', 'Rate', 'Net GBP', 'Status']];
        $nominal = Rows::of($this->workspace->organisation()['cost_categories'])->pluck('nominal_code', 'key');

        foreach ($this->workspace->supplierInvoices()->sortBy('invoice_date') as $si) {
            if (! in_array($si['status'], [SupplierInvoiceStatus::Approved->value, SupplierInvoiceStatus::Paid->value], true) || $si['invoice_date'] < $from || $si['invoice_date'] > $to) {
                continue;
            }

            $line = Rows::of($this->workspace->budget($si['event_id'])['lines'] ?? [])->firstWhere('id', $si['budget_line_id'] ?? ($this->workspace->purchaseOrders()->get($si['purchase_order_id'])['budget_line_id'] ?? null));
            $net = Money::of($si['net_minor'], $si['currency']);
            $gbp = $si['currency'] === 'GBP' ? $net : $net->convert((string) ($si['fx_rate'] ?? '1'), 'GBP');

            $rows[] = [
                $si['invoice_number'], $this->workspace->supplierName($si['supplier_id']), $this->workspace->suppliers()->get($si['supplier_id'])['vat_number'] ?? null,
                $this->workspace->event($si['event_id'])['code'] ?? null, $this->workspace->purchaseOrders()->get($si['purchase_order_id'])['code'] ?? null,
                $nominal[$line['category'] ?? ''] ?? null, $si['invoice_date'], $si['due_date'], $si['currency'],
                $this->amount($si['net_minor']), $this->amount($si['vat_minor']), $this->amount($si['gross_minor']), $si['fx_rate'], $this->amount($gbp->minor), $si['status'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function freelancerCosts(string $from, string $to): array
    {
        $rows = [['Person', 'Company', 'VAT registered', 'Date', 'Event', 'Days', 'Hours', 'Day rate', 'Cost', 'Approved by', 'Approved at']];

        foreach ($this->workspace->timesheets()->sortBy('week_start') as $sheet) {
            if (! $this->workspace->isFreelancer($sheet['person_id'])) {
                continue;
            }

            $person = $this->workspace->person($sheet['person_id']) ?? [];

            foreach ($sheet['entries'] as $entry) {
                if ($entry['status'] !== EntryStatus::Approved->value || ! $entry['event_id'] || $entry['date'] < $from || $entry['date'] > $to) {
                    continue;
                }

                $rate = $this->workspace->rateBook($sheet['person_id'])->rateOn($entry['date']);
                $cost = $this->workspace->entryCost($sheet['person_id'], $entry);

                $rows[] = [
                    $this->workspace->personName($sheet['person_id']), $person['company_name'] ?? null, ($person['vat_registered'] ?? false) ? 'Yes' : 'No',
                    $entry['date'], $this->workspace->event($entry['event_id'])['code'] ?? null, (string) $entry['days'], (string) $entry['hours'],
                    $rate ? $this->amount($rate->minor) : null, $cost ? $this->amount($cost->minor) : null,
                    $this->workspace->personName($entry['approved_by']), $entry['approved_at'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function expenses(string $from, string $to): array
    {
        $rows = [['Claim', 'Person', 'Date', 'Event', 'Category', 'Description', 'Miles', 'Currency', 'Amount', 'VAT', 'Rate', 'Amount GBP']];

        foreach ($this->workspace->expenses()->sortBy('date') as $x) {
            if ($x['status'] !== ExpenseStatus::Approved->value || $x['date'] < $from || $x['date'] > $to) {
                continue;
            }

            $rows[] = [
                'EX-'.$x['id'], $this->workspace->personName($x['person_id']), $x['date'], $this->workspace->event($x['event_id'])['code'] ?? null,
                $x['category'], $x['description'], $x['mileage_miles'], $x['currency'], $this->amount($x['amount_minor']), $this->amount($x['vat_minor']),
                $x['fx_rate'], $this->amount($x['amount_gbp_minor']),
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|null>>
     */
    private function billing(string $from, string $to): array
    {
        $rows = [['Event', 'Client', 'Milestone', 'Percentage', 'Amount', 'Currency', 'Due', 'Status', 'Invoice reference', 'Paid']];

        foreach ($this->workspace->billingMilestones()->sortBy('due_date') as $m) {
            if ($m['due_date'] < $from || $m['due_date'] > $to) {
                continue;
            }

            $event = $this->workspace->event($m['event_id']) ?? [];
            $rows[] = [
                $event['code'] ?? null, $this->workspace->clients()->get($event['client_id'] ?? null)['name'] ?? null, $m['description'], $m['percentage'],
                $this->amount($m['amount_minor']), $m['currency'], $m['due_date'], $m['status'], $m['invoice_reference'], $m['paid_at'],
            ];
        }

        return $rows;
    }

    /**
     * Pence as a plain decimal for spreadsheets: 123456 becomes 1234.56.
     */
    private function amount(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }
}
