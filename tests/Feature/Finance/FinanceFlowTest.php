<?php

use App\Actions\Events\ChangeEventStage;
use App\Actions\Finance\ApproveSupplierInvoice;
use App\Actions\Finance\ManageExpense;
use App\Actions\Finance\ManagePurchaseOrder;
use App\Actions\Finance\RecordSupplierInvoice;
use App\Actions\Finance\SavePurchaseOrder;
use App\Actions\Finance\SettleSupplierInvoice;
use App\Actions\Time\AddTimesheetEntry;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Actions\Time\SubmitTimesheet;
use App\Data\EventFinancials;
use App\Demo\Workspace;
use App\Enums\ApprovalStatus;
use App\Enums\EventStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\Approval;
use App\Models\CostEntry;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Storage::fake('local'));

function northwind(): EventFinancials
{
    app(Workspace::class)->forget();

    return app(Workspace::class)->financials(7);
}

/**
 * @param  list<array{0: string, 1: string}>  $lines  [unit price, VAT rate]
 */
function raisePo(User $by, int $eventId, int $lineId, string $price, string $currency = 'GBP'): PurchaseOrder
{
    return app(SavePurchaseOrder::class)->handle($by, [
        'event_id' => $eventId, 'supplier_id' => 5, 'budget_line_id' => $lineId, 'currency' => $currency,
        'lines' => [['description' => 'Extra breakout set dressing', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '20']],
    ]);
}

it('updates forecast and margin as POs, invoices, time and expenses are approved (phase 5 done, part 1)', function () {
    $start = northwind();

    // 1. Hannah raises a £3,000 PO on the creative line (£4,000 still estimated). She can't
    //    approve her own, so it goes to the operations director.
    $po = raisePo(persona('hannah'), 7, 5, '3000');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po);
    expect(Approval::query()->where('approvable_type', PurchaseOrder::class)->where('approvable_id', $po->id)->pluck('approver_id')->all())->toBe([1]);

    app(ManagePurchaseOrder::class)->decide(persona('priya'), $po->refresh(), true);
    $afterPo = northwind();

    expect($afterPo->committed->minus($start->committed)->minor)->toBe(300000)
        ->and($afterPo->forecast()->equals($start->forecast()))->toBeTrue()
        ->and($afterPo->marginPercent())->toBe($start->marginPercent());

    // 2. The invoice comes in £200 over: past the £150 tolerance, so it needs a note.
    app(ManagePurchaseOrder::class)->receiveGoods(persona('hannah'), $po->refresh());
    $invoice = app(RecordSupplierInvoice::class)->handle(persona('grace'), ['purchase_order_id' => $po->id, 'invoice_number' => 'CS-7781', 'invoice_date' => '2026-10-22', 'currency' => 'GBP', 'net' => '3200', 'vat_rate' => '20']);

    expect($invoice->status->value)->toBe('received');
    expect(fn () => app(ApproveSupplierInvoice::class)->handle(persona('grace'), $invoice->id))->toThrow(ValidationException::class);

    $movement = app(ApproveSupplierInvoice::class)->handle(persona('grace'), $invoice->id, 'Two extra plinths agreed on site; change request to follow.');
    $afterInvoice = northwind();

    expect($movement->minor)->toBe(20000)
        ->and($afterInvoice->committed->equals($start->committed))->toBeTrue()
        ->and($afterInvoice->actual->minus($afterPo->actual)->minor)->toBe(320000)
        ->and($afterInvoice->forecast()->minus($start->forecast())->minor)->toBe(20000)
        ->and($afterInvoice->margin()->minus($start->margin())->minor)->toBe(-20000)
        ->and($po->refresh()->status)->toBe(PurchaseOrderStatus::Invoiced);

    // 3. Kofi's Northwind time is approved: crew actual at his day rate, drawn from the crew estimate.
    $sheet = Timesheet::query()->where('person_id', 26)->whereDate('week_start', '2026-10-19')->firstOrFail();
    app(SubmitTimesheet::class)->handle(persona('kofi'), $sheet->id);

    foreach ($sheet->entries()->where('event_id', 7)->pluck('id') as $id) {
        app(ApproveTimesheetEntry::class)->handle(persona('hannah'), $id);
    }

    $afterTime = northwind();
    $days = $sheet->entries()->where('event_id', 7)->count();
    expect($afterTime->actual->minus($afterInvoice->actual)->minor)->toBe($days * $this->workspace()->rateBook(26)->rateOn('2026-10-20')->minor)
        ->and($afterTime->forecast()->equals($afterInvoice->forecast()))->toBeTrue();

    // 4. An expense, approved by the producer and then finance, becomes actual on the event.
    $expense = app(ManageExpense::class)->submit(persona('kofi'), ['event_id' => 7, 'category' => 'taxi', 'date' => '2026-10-22', 'description' => 'Taxi after 23:00 strike', 'amount' => '42.50', 'currency' => 'GBP', 'vat' => '7.08', 'receipt' => UploadedFile::fake()->image('taxi.jpg')]);

    expect(app(ManageExpense::class)->decide(persona('hannah'), $expense->id, true))->toBe(ApprovalStatus::Pending)
        ->and(northwind()->actual->equals($afterTime->actual))->toBeTrue()
        ->and(app(ManageExpense::class)->decide(persona('grace'), $expense->id, true))->toBe(ApprovalStatus::Approved);

    $end = northwind();
    expect($end->actual->minus($afterTime->actual)->minor)->toBe(4250)
        ->and(CostEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail()->budget_line_id)->toBe(11);
});

it('won’t reconcile an event with open items, and will once they’re cleared (phase 5 done, part 2; rule 13)', function () {
    // EV-26104 is in Wrap with a disputed invoice and an unapproved timesheet entry.
    $blockers = app(ChangeEventStage::class)->blockers(4, EventStage::Reconciled);
    expect($blockers)->toHaveCount(2);

    // Raising a PO adds an open item.
    $po = raisePo(persona('hannah'), 4, 21, '450');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po);
    expect(app(ChangeEventStage::class)->blockers(4, EventStage::Reconciled))->toContain("{$po->code} is still open (Pending approval)");

    expect(fn () => app(ChangeEventStage::class)->handle(persona('grace'), 4, EventStage::Reconciled))->toThrow(ValidationException::class);

    // Clear everything: cancel the PO, settle the invoice, approve the time.
    app(ManagePurchaseOrder::class)->cancel(persona('hannah'), $po->refresh(), 'Not needed after all.');

    $disputed = SupplierInvoice::query()->where('event_id', 4)->where('status', 'disputed')->firstOrFail();
    app(SettleSupplierInvoice::class)->reopen(persona('grace'), $disputed->id);
    app(ApproveSupplierInvoice::class)->handle(persona('grace'), $disputed->id, 'Credit note agreed for the damaged flats; balance accepted.');

    // Gemma logged a day on Meridian by mistake; it was returned, so she removes it.
    $gemma = User::factory()->withRole(Role::Freelancer)->create(['person_id' => 32]);
    app(AddTimesheetEntry::class)->delete($gemma, 31);

    $this->workspace()->forget();
    expect(app(ChangeEventStage::class)->blockers(4, EventStage::Reconciled))->toBe([]);

    app(ChangeEventStage::class)->handle(persona('grace'), 4, EventStage::Reconciled);
    expect($this->workspace()->event(4)['stage'])->toBe('reconciled');
});

it('routes POs by approval limit, never to the person who raised it (rules 5 and 10)', function () {
    $approvers = fn (PurchaseOrder $po) => Approval::query()->where('approvable_type', PurchaseOrder::class)->where('approvable_id', $po->id)->orderBy('step')->get(['approver_id', 'step', 'status'])
        ->map(fn ($a) => [$a->approver_id, $a->step, $a->status->value])->all();

    // Finance raises £4,000 on Hannah's event: within the producer's limit, so Hannah.
    $small = raisePo(persona('grace'), 7, 5, '4000');
    app(ManagePurchaseOrder::class)->submit(persona('grace'), $small);
    expect($approvers($small))->toBe([[4, 1, 'pending']]);

    // £12,000 is over the producer's limit: the operations director.
    $medium = raisePo(persona('grace'), 7, 5, '12000');
    app(ManagePurchaseOrder::class)->submit(persona('grace'), $medium);
    expect($approvers($medium))->toBe([[1, 1, 'pending']]);

    // £60,000 needs two signatures in turn: the operations director, then the head of finance.
    $large = raisePo(persona('hannah'), 7, 5, '60000');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $large);
    expect($approvers($large))->toBe([[1, 1, 'pending'], [20, 2, 'waiting']]);

    expect(app(ManagePurchaseOrder::class)->decide(persona('priya'), $large->refresh(), true))->toBe(ApprovalStatus::Pending)
        ->and($large->refresh()->status)->toBe(PurchaseOrderStatus::PendingApproval)
        ->and(app(ManagePurchaseOrder::class)->decide(persona('grace'), $large->refresh(), true))->toBe(ApprovalStatus::Approved)
        ->and($large->refresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('sends a PO back with a comment, and lets it be resubmitted', function () {
    $po = raisePo(persona('hannah'), 7, 5, '900');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po);

    expect(fn () => app(ManagePurchaseOrder::class)->decide(persona('priya'), $po->refresh(), false))->toThrow(ValidationException::class);
    app(ManagePurchaseOrder::class)->decide(persona('priya'), $po->refresh(), false, 'Use the existing scenic PO instead.');

    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::Draft);
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po->refresh());
    expect($po->refresh()->status)->toBe(PurchaseOrderStatus::PendingApproval);
});

it('commits a euro PO in sterling at the rate, keeping the original (rule 16)', function () {
    // Lisbon's venue line; EUR rate 0.8650.
    $line = collect($this->workspace()->budget(9)['lines'])->firstWhere('category', 'production') ?? collect($this->workspace()->budget(9)['lines'])->first();
    $po = raisePo(persona('hannah'), 9, $line['id'], '2000', 'EUR');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po);
    app(ManagePurchaseOrder::class)->decide(persona('priya'), $po->refresh(), true);

    $entry = CostEntry::query()->where('source_type', 'purchase-order')->where('source_id', $po->id)->firstOrFail();
    expect($entry->amount_minor)->toBe(200000)->and($entry->currency)->toBe('EUR')->and($entry->fx_rate)->toBe('0.8650')->and($entry->amount_gbp_minor)->toBe(173000);
});

it('gives the estimate back when an approved PO is cancelled', function () {
    $before = northwind();
    $po = raisePo(persona('hannah'), 7, 5, '3000');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po);
    app(ManagePurchaseOrder::class)->decide(persona('priya'), $po->refresh(), true);
    app(ManagePurchaseOrder::class)->cancel(persona('hannah'), $po->refresh(), 'Client dropped the breakout.');

    $after = northwind();
    expect($after->committed->equals($before->committed))->toBeTrue()
        ->and($after->remaining->equals($before->remaining))->toBeTrue()
        ->and($po->refresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('needs a reason for an invoice without a PO, and finance to approve it', function () {
    $record = app(RecordSupplierInvoice::class);
    $base = ['supplier_id' => 5, 'event_id' => 7, 'budget_line_id' => 11, 'invoice_number' => 'CS-8001', 'invoice_date' => '2026-10-22', 'currency' => 'GBP', 'net' => '180', 'vat_rate' => '20'];

    expect(fn () => $record->handle(persona('grace'), $base))->toThrow(ValidationException::class);

    $invoice = $record->handle(persona('grace'), $base + ['no_po_reason' => 'Emergency gaffer tape run on show day.']);
    $people = Approval::query()->where('approvable_type', SupplierInvoice::class)->where('approvable_id', $invoice->id)->pluck('approver_id')->sort()->values()->all();

    // Finance and the operations director; not the producer, as there is no PO.
    expect($people)->toBe([1, 20]);
    expect(fn () => app(ApproveSupplierInvoice::class)->handle(persona('grace'), $invoice->id))->toThrow(ValidationException::class);
    app(ApproveSupplierInvoice::class)->handle(persona('grace'), $invoice->id, 'Show-day emergency purchase, receipt attached.');
    expect($invoice->refresh()->status->value)->toBe('approved');
});

it('matches an invoice once goods are received and it is within tolerance', function () {
    $po = raisePo(persona('hannah'), 7, 5, '1000');
    app(ManagePurchaseOrder::class)->submit(persona('hannah'), $po);
    app(ManagePurchaseOrder::class)->decide(persona('priya'), $po->refresh(), true);

    $invoice = app(RecordSupplierInvoice::class)->handle(persona('grace'), ['purchase_order_id' => $po->id, 'invoice_number' => 'CS-9001', 'invoice_date' => '2026-10-22', 'currency' => 'GBP', 'net' => '1020', 'vat_rate' => '20']);
    expect($invoice->status->value)->toBe('received');

    app(ManagePurchaseOrder::class)->receiveGoods(persona('hannah'), $po->refresh());
    expect($invoice->refresh()->status->value)->toBe('matched');

    // Within tolerance against a PO, the producer may approve it too.
    app(ApproveSupplierInvoice::class)->handle(persona('hannah'), $invoice->id);
    expect($invoice->refresh()->status->value)->toBe('approved');
});

it('disputes an invoice and records payment once approved', function () {
    $invoice = SupplierInvoice::query()->whereIn('status', ['received', 'matched'])->firstOrFail();
    app(SettleSupplierInvoice::class)->dispute(persona('grace'), $invoice->id, 'Quantity of LED panels does not match delivery note.');

    expect($invoice->refresh()->status->value)->toBe('disputed')
        ->and(Approval::query()->where('approvable_type', SupplierInvoice::class)->where('approvable_id', $invoice->id)->where('status', 'pending')->count())->toBe(0);

    $approved = SupplierInvoice::query()->where('status', 'approved')->firstOrFail();
    app(SettleSupplierInvoice::class)->markPaid(persona('grace'), $approved->id, '2026-10-23');
    expect($approved->refresh()->status->value)->toBe('paid');
});

it('keeps money exact: a PO total is the sum of its lines (rule 15)', function () {
    $po = app(SavePurchaseOrder::class)->handle(persona('hannah'), [
        'event_id' => 7, 'supplier_id' => 5, 'budget_line_id' => 5, 'currency' => 'GBP',
        'lines' => [
            ['description' => 'Plinths', 'quantity' => '3', 'unit_price' => '33.33', 'vat_rate' => '20'],
            ['description' => 'Delivery', 'quantity' => '1.5', 'unit_price' => '10.01', 'vat_rate' => '5'],
            ['description' => 'Insurance', 'quantity' => '1', 'unit_price' => '12.00', 'vat_rate' => 'exempt'],
        ],
    ]);

    expect($po->subtotal_minor)->toBe(9999 + 1502 + 1200)
        ->and($po->vat_minor)->toBe(2000 + 75)
        ->and($po->total_minor)->toBe($po->subtotal_minor + $po->vat_minor);
});
