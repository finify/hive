<?php

namespace App\Actions\Finance;

use App\Demo\Workspace;
use App\Models\CostEntry;
use App\Models\PurchaseOrder;
use App\Support\Money;

/**
 * Writes cost entries, so committed, actual and the remaining estimate move
 * the same way everywhere:
 *
 * - an approved PO commits its net amount and draws down the line's
 *   remaining estimate (the forecast moves only if it overspends);
 * - an approved invoice releases what it covers of the PO's commitment and
 *   posts the actual, so the forecast moves by the difference;
 * - approved time and expenses post actuals that draw down the remaining
 *   estimate;
 * - cancelling a PO releases its commitment and gives the estimate back.
 *
 * Foreign amounts keep the original, the rate and sterling (rule 16).
 */
class PostCost
{
    public function __construct(private readonly Workspace $workspace) {}

    public function commit(PurchaseOrder $po): CostEntry
    {
        return $this->post($po->event_id, $po->budget_line_id, 'purchase-order', $po->id, $po->code, 'committed', Money::of($po->subtotal_minor, $po->currency), $po->fx_rate, true);
    }

    /**
     * What is still committed on a PO, in GBP.
     */
    public function outstanding(int $purchaseOrderId): Money
    {
        $committed = $this->workspace->costEntries()
            ->filter(fn ($c) => $c['kind'] === 'committed' && ($c['source']['type'] ?? null) === 'purchase-order' && ($c['source']['id'] ?? null) === $purchaseOrderId)
            ->sum('amount_gbp_minor');

        return Money::of(max(0, (int) $committed));
    }

    /**
     * Releases up to $amount of a PO's commitment. Returns what was released.
     */
    public function release(PurchaseOrder $po, Money $amount, string $ref, bool $giveBackEstimate = false): Money
    {
        $released = Money::of(min($amount->minor, $this->outstanding($po->id)->minor));

        if ($released->isPositive()) {
            CostEntry::query()->create([
                'event_id' => $po->event_id, 'budget_line_id' => $po->budget_line_id, 'source_type' => 'purchase-order', 'source_id' => $po->id,
                'source_ref' => $ref, 'kind' => 'committed', 'amount_minor' => -$released->minor, 'currency' => 'GBP',
                'amount_gbp_minor' => -$released->minor, 'draws_down_remaining' => $giveBackEstimate, 'posted_at' => now(),
            ]);
            $this->workspace->forget();
        }

        return $released;
    }

    public function actual(int $eventId, ?int $lineId, string $sourceType, int $sourceId, string $ref, Money $amount, ?string $fxRate, bool $drawsDown): CostEntry
    {
        return $this->post($eventId, $lineId, $sourceType, $sourceId, $ref, 'actual', $amount, $fxRate, $drawsDown);
    }

    public function toGbp(Money $amount, ?string $fxRate): Money
    {
        if ($amount->currency === 'GBP') {
            return $amount;
        }

        $rate = $fxRate ?? $this->workspace->exchangeRate($amount->currency) ?? '1';

        return $amount->convert($rate, 'GBP');
    }

    private function post(int $eventId, ?int $lineId, string $sourceType, int $sourceId, string $ref, string $kind, Money $amount, ?string $fxRate, bool $drawsDown): CostEntry
    {
        $rate = $amount->currency === 'GBP' ? null : ($fxRate ?? $this->workspace->exchangeRate($amount->currency));

        $entry = CostEntry::query()->create([
            'event_id' => $eventId, 'budget_line_id' => $lineId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_ref' => $ref,
            'kind' => $kind, 'amount_minor' => $amount->minor, 'currency' => $amount->currency, 'fx_rate' => $rate,
            'amount_gbp_minor' => $this->toGbp($amount, $rate)->minor, 'draws_down_remaining' => $drawsDown, 'posted_at' => now(),
        ]);

        $this->workspace->forget();

        return $entry;
    }
}
