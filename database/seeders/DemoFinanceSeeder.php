<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Demo\Rows;
use App\Demo\Workspace;
use App\Enums\BudgetStatus;
use App\Enums\DocumentType;
use App\Enums\ExpenseStatus;
use App\Enums\FreelancerInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\VatRate;
use App\Models\Approval;
use App\Models\BillingMilestone;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostEntry;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\FreelancerInvoice;
use App\Models\Person;
use App\Models\PurchaseOrder;
use App\Models\RevenueLine;
use App\Models\SupplierInvoice;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads budgets, revenue, purchase orders, invoices, expenses, billing,
 * exchange rates and cost entries from database/demo with their ids, then
 * opens approvals for what is still waiting. Runs after the demo users
 * (finance approves) and timesheets (freelancer invoices match approved time).
 */
class DemoFinanceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        $data = app(DemoData::class);

        DB::transaction(function () use ($data) {
            foreach (Rows::of($data->raw('organisation')['exchange_rates']) as $rate) {
                ExchangeRate::query()->create(['currency' => $rate['currency'], 'rate_to_gbp' => $rate['rate_to_gbp'], 'effective_on' => $rate['date'], 'set_by' => 20]);
            }

            foreach (Rows::of($data->raw('budgets')) as $budget) {
                $lines = [];

                foreach (Rows::of($budget['lines']) as $i => $line) {
                    $lines[] = BudgetLine::query()->forceCreate(['event_id' => $budget['event_id'], 'sort_order' => $i] + array_intersect_key($line, array_flip([
                        'id', 'category', 'description', 'supplier_id', 'budget_minor', 'currency', 'fx_rate', 'budget_gbp_minor', 'remaining_gbp_minor',
                    ])))->toWorkspaceArray();
                }

                $total = array_sum(array_column($lines, 'budget_gbp_minor'));

                // A revised budget keeps its earlier version as superseded history.
                for ($v = 1; $v < $budget['version']; $v++) {
                    Budget::query()->create([
                        'event_id' => $budget['event_id'], 'version' => $v, 'status' => BudgetStatus::Revised,
                        'total_gbp_minor' => $budget['revised_from_minor'] ?? $total, 'lines_snapshot' => $lines,
                        'approved_by' => $budget['approved_by'], 'approved_at' => $budget['approved_at'],
                    ]);
                }

                Budget::query()->create([
                    'event_id' => $budget['event_id'], 'version' => $budget['version'], 'status' => BudgetStatus::Approved,
                    'total_gbp_minor' => $total, 'lines_snapshot' => $lines,
                    'approved_by' => $budget['approved_by'], 'approved_at' => $budget['approved_at'],
                ]);
            }

            foreach (Rows::of($data->raw('revenue_lines')) as $row) {
                RevenueLine::query()->forceCreate($row);
            }

            foreach (Rows::of($data->raw('purchase_orders')) as $row) {
                $po = PurchaseOrder::query()->forceCreate([
                    'id' => $row['id'], 'code' => $row['code'], 'event_id' => $row['event_id'], 'supplier_id' => $row['supplier_id'],
                    'budget_line_id' => $row['budget_line_id'], 'status' => $row['status'], 'currency' => $row['currency'], 'fx_rate' => $row['fx_rate'],
                    'subtotal_minor' => $row['subtotal_minor'], 'vat_minor' => $row['vat_minor'], 'total_minor' => $row['total_minor'],
                    'raised_by' => $row['raised_by'], 'approved_by' => $row['approved_by'],
                    'approved_at' => $row['approved_by'] ? app(DemoData::class)->at('2026-06-01 09:00:00') : null,
                    'sent_at' => in_array($row['status'], ['sent', 'partially-invoiced', 'invoiced'], true) ? app(DemoData::class)->at('2026-06-02 09:00:00') : null,
                    'goods_received_at' => $row['goods_received'] ? app(DemoData::class)->at('2026-10-01 09:00:00') : null,
                ]);

                foreach (Rows::of($row['lines']) as $line) {
                    $po->lines()->create(['description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price_minor' => $line['unit_price_minor'], 'vat_rate' => (string) $line['vat_rate']]);
                }
            }

            foreach (Rows::of($data->raw('supplier_invoices')) as $row) {
                SupplierInvoice::query()->forceCreate([
                    'approved_at' => $row['approved_by'] ? $row['received_at'] : null,
                    'paid_on' => $row['status'] === 'paid' ? $row['due_date'] : null,
                ] + array_intersect_key($row, array_flip([
                    'id', 'supplier_id', 'purchase_order_id', 'event_id', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'fx_rate',
                    'net_minor', 'vat_minor', 'gross_minor', 'status', 'variance_minor', 'approved_by', 'notes', 'received_at',
                ])));
            }

            foreach (Rows::of($data->raw('expenses')) as $row) {
                $gbp = $row['currency'] === 'GBP' ? $row['amount_minor'] : Money::of($row['amount_minor'], $row['currency'])->convert((string) $this->rate($row['currency']), 'GBP')->minor;
                Expense::query()->forceCreate([
                    'id' => $row['id'], 'person_id' => $row['person_id'], 'event_id' => $row['event_id'], 'category' => $row['category'],
                    'date' => $row['date'], 'description' => $row['description'], 'amount_minor' => $row['amount_minor'], 'currency' => $row['currency'],
                    'amount_gbp_minor' => $gbp, 'vat_minor' => $row['vat_minor'], 'mileage_miles' => $row['mileage_miles'],
                    'receipt_name' => $row['receipt'] ? basename((string) $row['receipt']) : null, 'status' => $row['status'], 'submitted_at' => $row['date'].' 18:00:00',
                ]);
            }

            foreach (Rows::of($data->raw('billing_milestones')) as $row) {
                BillingMilestone::query()->forceCreate([
                    'id' => $row['id'], 'event_id' => $row['event_id'], 'description' => $row['description'], 'percentage' => $row['percentage'],
                    'amount_minor' => $row['amount_minor'], 'currency' => $row['currency'], 'due_date' => $row['due_date'], 'status' => $row['status'],
                    'invoice_reference' => $row['invoice_reference'], 'paid_on' => $row['paid_at'],
                    'invoiced_on' => $row['invoice_reference'] ? $row['due_date'] : null,
                ]);
            }

            foreach (Rows::of($data->raw('cost_entries')) as $row) {
                $source = is_array($row['source']) ? $row['source'] : [];
                CostEntry::query()->forceCreate([
                    'id' => $row['id'], 'event_id' => $row['event_id'], 'budget_line_id' => $row['budget_line_id'],
                    'source_type' => $source['type'] ?? 'manual', 'source_id' => $source['id'] ?? null, 'source_ref' => $source['ref'] ?? null,
                    'kind' => $row['kind'], 'amount_minor' => $row['amount_minor'], 'currency' => $row['currency'], 'fx_rate' => $row['fx_rate'],
                    'amount_gbp_minor' => $row['amount_gbp_minor'], 'posted_at' => $row['posted_at'],
                ]);
            }

            // Arjun has a self-billing agreement, so Hive can raise his invoices.
            Person::query()->findOrFail(31)->documents()->create([
                'type' => DocumentType::SelfBillingAgreement, 'title' => 'Self-billing agreement', 'issued_at' => app(DemoData::class)->at('2026-01-10'), 'expires_at' => app(DemoData::class)->at('2027-01-09'), 'mandatory' => false,
            ]);

            // Kofi's September invoice, matched to his approved time and paid.
            // Hollie's September invoice matched her approved time and is paid.
            // Yasmin's is £60 over hers and waits for finance.
            $workspace = app(Workspace::class);
            $hollie = $workspace->approvedTimeCost(30, app(DemoData::class)->at('2026-09-01'), app(DemoData::class)->at('2026-09-30'))->minor;
            FreelancerInvoice::query()->create([
                'person_id' => 30, 'period_start' => app(DemoData::class)->at('2026-09-01'), 'period_end' => app(DemoData::class)->at('2026-09-30'), 'invoice_number' => 'HS-2609',
                'net_minor' => $hollie, 'vat_minor' => 0, 'gross_minor' => $hollie, 'expected_minor' => $hollie,
                'status' => FreelancerInvoiceStatus::Paid, 'approved_by' => 20, 'approved_at' => app(DemoData::class)->at('2026-10-06 10:00:00'), 'paid_on' => app(DemoData::class)->at('2026-10-15'),
                'original_name' => 'HS-2609.pdf',
            ]);

            $yasmin = $workspace->approvedTimeCost(38, app(DemoData::class)->at('2026-09-01'), app(DemoData::class)->at('2026-09-30'));
            $net = $yasmin->plus(Money::of(6000));
            $vat = VatRate::Standard->on($net);
            FreelancerInvoice::query()->create([
                'person_id' => 38, 'period_start' => app(DemoData::class)->at('2026-09-01'), 'period_end' => app(DemoData::class)->at('2026-09-30'), 'invoice_number' => 'YF-114',
                'net_minor' => $net->minor, 'vat_minor' => $vat->minor, 'gross_minor' => $net->plus($vat)->minor,
                'expected_minor' => $yasmin->minor, 'status' => FreelancerInvoiceStatus::Received, 'original_name' => 'YF-114.pdf',
                'notes' => 'Includes a parking charge.', 'created_at' => app(DemoData::class)->at('2026-10-12 09:00:00'),
            ]);
        });

        $this->openApprovals();
    }

    private function openApprovals(): void
    {
        $engine = app(ApprovalEngine::class);

        foreach (PurchaseOrder::query()->where('status', PurchaseOrderStatus::PendingApproval)->get() as $po) {
            $this->backdate($engine->request($po), app(DemoData::class)->at('2026-10-19 10:00:00'));
        }

        foreach (SupplierInvoice::query()->whereIn('status', [SupplierInvoiceStatus::Received, SupplierInvoiceStatus::Matched])->get() as $invoice) {
            $this->backdate($engine->request($invoice), $invoice->received_at?->toDateTimeString());
        }

        foreach (FreelancerInvoice::query()->where('status', FreelancerInvoiceStatus::Received)->get() as $invoice) {
            $this->backdate($engine->request($invoice), $invoice->created_at->toDateTimeString());
        }

        foreach (Expense::query()->where('status', ExpenseStatus::Pending)->get() as $expense) {
            $this->backdate($engine->request($expense), $expense->submitted_at?->toDateTimeString());
        }
    }

    /**
     * @param  Collection<int, Approval>  $rows
     */
    private function backdate(Collection $rows, ?string $at): void
    {
        if ($at !== null) {
            Approval::query()->whereIn('id', $rows->pluck('id'))->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    private function rate(string $currency): string
    {
        return (string) (ExchangeRate::query()->where('currency', $currency)->latest('effective_on')->value('rate_to_gbp') ?? '1');
    }
}
