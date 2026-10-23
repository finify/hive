<?php

namespace App\Actions\Finance;

use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\PurchaseOrderStatus;
use App\Enums\VatRate;
use App\Models\BudgetLine;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Raises or changes a draft purchase order against a budget line and a
 * supplier. Producers raise them on their own events; finance on any.
 */
class SavePurchaseOrder
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?PurchaseOrder $po = null): PurchaseOrder
    {
        $data = Validator::make($input, [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'budget_line_id' => ['required', 'integer', 'exists:budget_lines,id'],
            'currency' => ['required', Rule::in(config('hive.finance.currencies'))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'lines.*.unit_price' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'lines.*.vat_rate' => ['required', Rule::enum(VatRate::class)],
        ], [
            'lines.required' => 'Add at least one line.',
            'lines.*.unit_price.regex' => 'Enter a price, for example 1250 or 1250.50.',
            'budget_line_id.required' => 'Choose the budget line this spend comes from.',
            'supplier_id.required' => 'Choose the supplier.',
            'event_id.required' => 'Choose the event.',
        ])->validate();

        $event = $this->workspace->event((int) $data['event_id']);
        abort_unless($event !== null && $this->canRaise($user, $event), 403);

        if ($po !== null && $po->status !== PurchaseOrderStatus::Draft) {
            throw ValidationException::withMessages(['po' => 'Only a draft PO can change. Cancel it and raise a new one.']);
        }

        if (BudgetLine::query()->whereKey($data['budget_line_id'])->value('event_id') !== (int) $data['event_id']) {
            throw ValidationException::withMessages(['budget_line_id' => 'Choose a line on this event’s budget.']);
        }

        $currency = $data['currency'];
        $subtotal = Money::zero($currency);
        $vat = Money::zero($currency);
        $lines = [];

        foreach ($data['lines'] as $line) {
            // Quantities to two decimal places, multiplied exactly in pence.
            $hundredths = (int) round(((float) $line['quantity']) * 100);
            $net = Money::parse((string) $line['unit_price'], $currency)->multiplyRatio($hundredths, 100);
            $subtotal = $subtotal->plus($net);
            $vat = $vat->plus(VatRate::from($line['vat_rate'])->on($net));
            $lines[] = ['description' => $line['description'], 'quantity' => $hundredths / 100, 'unit_price_minor' => Money::parse((string) $line['unit_price'], $currency)->minor, 'vat_rate' => $line['vat_rate']];
        }

        $creating = $po === null;

        $po = DB::transaction(function () use ($po, $data, $user, $currency, $subtotal, $vat, $lines) {
            $po ??= new PurchaseOrder(['code' => $this->nextCode(), 'status' => PurchaseOrderStatus::Draft, 'raised_by' => $user->person_id]);

            $po->fill([
                'event_id' => (int) $data['event_id'], 'supplier_id' => (int) $data['supplier_id'], 'budget_line_id' => (int) $data['budget_line_id'],
                'currency' => $currency, 'fx_rate' => $currency === 'GBP' ? null : $this->workspace->exchangeRate($currency),
                'subtotal_minor' => $subtotal->minor, 'vat_minor' => $vat->minor, 'total_minor' => $subtotal->plus($vat)->minor, 'notes' => $data['notes'] ?? null,
            ])->save();

            $po->lines()->delete();
            $po->lines()->createMany($lines);

            return $po;
        });

        $this->workspace->forget();

        activity('finance')
            ->causedBy($user)
            ->performedOn($po)
            ->withProperties(['ref' => $po->code, 'attributes' => ['event' => $event['code'], 'net' => $subtotal->format(), 'lines' => count($lines)]])
            ->event($creating ? 'po-raised' : 'po-updated')
            ->log(($creating ? 'Raised ' : 'Updated ')."{$po->code} for {$subtotal->format()} on {$event['code']}");

        return $po;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function canRaise(User $user, array $event): bool
    {
        return $user->can('po.create') && (new Viewer($user, $this->workspace))->canSeeBudget($event);
    }

    /**
     * PO-, the two-digit year, then a four-digit number for the year.
     */
    public function nextCode(): string
    {
        $prefix = 'PO-'.$this->workspace->today()->format('y').'-';
        $last = PurchaseOrder::query()->where('code', 'like', $prefix.'%')->pluck('code')->map(fn (string $c) => (int) substr($c, strlen($prefix)))->max();

        return $prefix.str_pad((string) (($last ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }
}
