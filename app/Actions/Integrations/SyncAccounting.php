<?php

namespace App\Actions\Integrations;

use App\Demo\Workspace;
use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Services\Accounting\AccountingGateway;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes approved supplier invoices to the accounting system as bills, and
 * records the ones it says are paid. Each bill is pushed once (its
 * accounting id is kept); a failure on one doesn't stop the rest.
 */
class SyncAccounting
{
    public function __construct(
        private readonly AccountingGateway $gateway,
        private readonly Workspace $workspace,
    ) {}

    /**
     * @return array{pushed: int, paid: int, failed: int}
     */
    public function handle(): array
    {
        if (! $this->gateway->isConfigured()) {
            return ['pushed' => 0, 'paid' => 0, 'failed' => 0];
        }

        $pushed = $failed = 0;

        foreach (SupplierInvoice::query()->with(['supplier', 'purchaseOrder'])->where('status', SupplierInvoiceStatus::Approved)->whereNull('accounting_id')->get() as $invoice) {
            try {
                $id = $this->gateway->pushBill($this->bill($invoice));
            } catch (Throwable $e) {
                Log::warning('Accounting push failed', ['invoice' => $invoice->invoice_number, 'error' => $e->getMessage()]);
                $failed++;

                continue;
            }

            $invoice->update(['accounting_id' => $id, 'accounting_synced_at' => now()]);
            $this->log($invoice, 'bill-pushed', "Sent invoice {$invoice->invoice_number} to {$this->gateway->name()}", ['accounting_id' => $id]);
            $pushed++;
        }

        $open = SupplierInvoice::query()->where('status', SupplierInvoiceStatus::Approved)->whereNotNull('accounting_id')->get()->keyBy('accounting_id');
        $paid = 0;

        try {
            $status = $this->gateway->paymentStatus($open->keys()->map(fn ($k) => (string) $k)->values()->all());
        } catch (Throwable $e) {
            Log::warning('Accounting payment status failed', ['error' => $e->getMessage()]);
            $status = [];
            $failed++;
        }

        foreach ($status as $id => $paidOn) {
            $invoice = $open->get($id);

            if ($invoice === null || $paidOn === null) {
                continue;
            }

            $invoice->update(['status' => SupplierInvoiceStatus::Paid, 'paid_on' => $paidOn, 'accounting_synced_at' => now()]);
            $this->log($invoice, 'paid', "Recorded invoice {$invoice->invoice_number} as paid, from {$this->gateway->name()}", ['status' => 'paid', 'paid_on' => $paidOn]);
            $paid++;
        }

        $this->workspace->forget();

        return ['pushed' => $pushed, 'paid' => $paid, 'failed' => $failed];
    }

    /**
     * @return array{supplier: string, number: string, reference: string, date: string, due_date: string|null, currency: string, lines: list<array{description: string, net: string, account: string, vat_rate: int}>}
     */
    private function bill(SupplierInvoice $invoice): array
    {
        $event = $this->workspace->event($invoice->event_id);
        $line = $this->workspace->budgetLines((int) $invoice->event_id)->firstWhere('id', $invoice->budget_line_id);
        $category = $line['category'] ?? null;
        $net = Money::of($invoice->net_minor, $invoice->currency);

        return [
            'supplier' => $invoice->supplier->name ?? 'Unknown supplier',
            'number' => $invoice->invoice_number,
            'reference' => trim(($invoice->purchaseOrder->code ?? '').' '.($event['code'] ?? '')),
            'date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'lines' => [[
                'description' => trim(($event['code'] ?? '').' '.($event['name'] ?? '').($category ? ', '.$this->workspace->costCategoryName($category) : '')),
                'net' => sprintf('%d.%02d', intdiv($net->minor, 100), abs($net->minor) % 100),
                'account' => (string) config('hive.integrations.xero.accounts.'.$category, config('hive.integrations.xero.default_account')),
                'vat_rate' => $invoice->vat_minor > 0 ? 20 : 0,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(SupplierInvoice $invoice, string $event, string $message, array $attributes): void
    {
        activity('finance')->performedOn($invoice)
            ->withProperties(['ref' => $invoice->invoice_number, 'attributes' => $attributes + ['source' => $this->gateway->name()]])
            ->event($event)->log($message);
    }
}
