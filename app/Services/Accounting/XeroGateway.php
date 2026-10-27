<?php

namespace App\Services\Accounting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Xero through a custom connection: a client-credentials token, bills as
 * ACCPAY invoices, payment status from FullyPaidOnDate. Tested with
 * Http::fake().
 */
class XeroGateway implements AccountingGateway
{
    private const API = 'https://api.xero.com/api.xro/2.0';

    public function name(): string
    {
        return 'Xero';
    }

    public function isConfigured(): bool
    {
        return filled(config('hive.integrations.xero.client_id')) && filled(config('hive.integrations.xero.client_secret'));
    }

    public function pushBill(array $bill): string
    {
        $response = $this->client()->post(self::API.'/Invoices', ['Invoices' => [[
            'Type' => 'ACCPAY',
            'Contact' => ['Name' => $bill['supplier']],
            'InvoiceNumber' => $bill['number'],
            'Reference' => $bill['reference'],
            'Date' => $bill['date'],
            'DueDate' => $bill['due_date'] ?? $bill['date'],
            'CurrencyCode' => $bill['currency'],
            'LineAmountTypes' => 'Exclusive',
            'Status' => 'AUTHORISED',
            'LineItems' => array_map(fn ($l) => [
                'Description' => $l['description'], 'Quantity' => 1, 'UnitAmount' => $l['net'],
                'AccountCode' => $l['account'], 'TaxType' => $l['vat_rate'] > 0 ? 'INPUT2' : 'NONE',
            ], $bill['lines']),
        ]]]);

        $id = $response->throw()->json('Invoices.0.InvoiceID');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Xero did not return an invoice id.');
        }

        return $id;
    }

    public function paymentStatus(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $status = array_fill_keys($ids, null);

        foreach (array_chunk($ids, 40) as $chunk) {
            foreach ((array) $this->client()->get(self::API.'/Invoices', ['IDs' => implode(',', $chunk)])->throw()->json('Invoices', []) as $invoice) {
                if (($invoice['Status'] ?? '') === 'PAID' && isset($invoice['InvoiceID'])) {
                    $status[$invoice['InvoiceID']] = self::date((string) ($invoice['FullyPaidOnDate'] ?? ''));
                }
            }
        }

        return $status;
    }

    /**
     * Xero sends dates as "/Date(1700000000000+0000)/".
     */
    public static function date(string $value): ?string
    {
        return preg_match('/\/Date\((\d+)/', $value, $m) ? gmdate('Y-m-d', intdiv((int) $m[1], 1000)) : (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null);
    }

    private function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Xero is not connected.');
        }

        $token = Cache::remember('xero.token', now()->addMinutes(25), fn () => Http::asForm()
            ->withBasicAuth((string) config('hive.integrations.xero.client_id'), (string) config('hive.integrations.xero.client_secret'))
            ->post('https://identity.xero.com/connect/token', ['grant_type' => 'client_credentials', 'scope' => 'accounting.transactions accounting.contacts'])
            ->throw()->json('access_token'));

        return Http::withToken((string) $token)->acceptJson()->timeout(20)->retry(2, 500, fn (\Throwable $e) => $e instanceof ConnectionException, throw: false)
            ->withHeaders(array_filter(['Xero-tenant-id' => config('hive.integrations.xero.tenant_id')]));
    }
}
