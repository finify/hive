<?php

namespace App\Services\Accounting;

/**
 * The accounting system (section 5.13): Hive pushes approved supplier bills
 * and pulls back whether they have been paid.
 */
interface AccountingGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @param  array{supplier: string, number: string, reference: string, date: string, due_date: string|null, currency: string,
     *               lines: list<array{description: string, net: string, account: string, vat_rate: int}>}  $bill
     * @return string the accounting system's id for the bill
     */
    public function pushBill(array $bill): string;

    /**
     * @param  list<string>  $ids
     * @return array<string, string|null> id => date paid (Y-m-d), or null if not paid
     */
    public function paymentStatus(array $ids): array;
}
