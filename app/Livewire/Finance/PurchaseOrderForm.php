<?php

namespace App\Livewire\Finance;

use App\Actions\Finance\SavePurchaseOrder;
use App\Demo\Rows;
use App\Enums\PurchaseOrderStatus;
use App\Enums\VatRate;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Raise a purchase order, or change a draft.
 */
#[Layout('layouts.app')]
class PurchaseOrderForm extends Component
{
    use UsesWorkspace;

    public ?int $poId = null;

    #[Url(as: 'event', except: '')]
    public string $eventId = '';

    public string $supplierId = '';

    #[Url(as: 'line', except: '')]
    public string $budgetLineId = '';

    public string $currency = 'GBP';

    public string $notes = '';

    /** @var list<array{description: string, quantity: string, unit_price: string, vat_rate: string}> */
    public array $lines = [];

    public function mount(?string $code = null): void
    {
        if ($code !== null) {
            $po = PurchaseOrder::query()->with('lines')->where('code', $code)->firstOrFail();
            abort_unless($po->status === PurchaseOrderStatus::Draft, 404);
            abort_unless(app(SavePurchaseOrder::class)->canRaise(auth()->user(), $this->workspace()->event($po->event_id) ?? []), 403);

            $this->poId = $po->id;
            $this->eventId = (string) $po->event_id;
            $this->supplierId = (string) $po->supplier_id;
            $this->budgetLineId = (string) $po->budget_line_id;
            $this->currency = $po->currency;
            $this->notes = (string) $po->notes;
            $this->lines = $po->lines->map(fn ($l) => [
                'description' => $l->description, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'),
                'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', ''), 'vat_rate' => $l->vat_rate,
            ])->values()->all();

            return;
        }

        abort_unless(auth()->user()->can('po.create'), 403);
        $this->lines = [['description' => '', 'quantity' => '1', 'unit_price' => '', 'vat_rate' => VatRate::Standard->value]];

        if ($this->budgetLineId !== '' && $this->eventId !== '') {
            $line = Rows::of($this->workspace()->budget((int) $this->eventId)['lines'] ?? [])->firstWhere('id', (int) $this->budgetLineId);
            $this->supplierId = (string) ($line['supplier_id'] ?? '');
            $this->currency = $line['currency'] ?? 'GBP';
            $this->lines[0]['description'] = $line['description'] ?? '';
        }
    }

    public function updatedEventId(): void
    {
        $this->budgetLineId = '';
    }

    public function addLine(): void
    {
        $this->lines[] = ['description' => '', 'quantity' => '1', 'unit_price' => '', 'vat_rate' => VatRate::Standard->value];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function save(SavePurchaseOrder $save): void
    {
        $this->resetValidation();
        $existing = $this->poId ? PurchaseOrder::query()->findOrFail($this->poId) : null;

        try {
            $po = $save->handle(auth()->user(), [
                'event_id' => $this->eventId ?: null, 'supplier_id' => $this->supplierId ?: null, 'budget_line_id' => $this->budgetLineId ?: null,
                'currency' => $this->currency, 'notes' => $this->notes ?: null, 'lines' => $this->lines,
            ], $existing);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        session()->flash('toast', ['message' => $existing ? "Saved {$po->code}." : "Raised {$po->code} as a draft. Submit it for approval when ready."]);
        $this->redirectRoute('purchase-orders.show', $po->code, navigate: true);
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $viewer = $this->viewer();
        $save = app(SavePurchaseOrder::class);
        $events = $viewer->events()->filter(fn ($e) => $save->canRaise(auth()->user(), $e) && ! in_array($e['stage'], ['cancelled', 'lost', 'closed', 'reconciled'], true) && $workspace->budget($e['id']))->sortBy('code');

        $subtotal = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            if (is_numeric($line['quantity']) && preg_match('/^\d{1,9}(\.\d{1,2})?$/', (string) $line['unit_price']) === 1) {
                $subtotal = $subtotal->plus(Money::parse((string) $line['unit_price'], $this->currency)->multiplyRatio((int) round((float) $line['quantity'] * 100), 100));
            }
        }

        return view('livewire.finance.purchase-order-form', [
            'events' => $events,
            'budgetLines' => $this->eventId !== '' ? collect($workspace->budgetLines((int) $this->eventId)) : collect(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'is_approved']),
            'vatRates' => VatRate::cases(),
            'currencies' => config('hive.finance.currencies'),
            'subtotal' => $subtotal,
            'limits' => config('hive.finance.po_limits'),
        ])->title($this->poId ? 'Edit purchase order' : 'Raise a purchase order');
    }
}
