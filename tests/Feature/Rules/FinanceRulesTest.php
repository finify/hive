<?php

use App\Actions\Finance\ApproveSupplierInvoice;
use App\Enums\HealthStatus;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

function brightline(): array
{
    return test()->workspace()->supplierInvoices()->firstWhere('invoice_number', 'BL-20931');
}

it('flags the Brightline invoice as over tolerance against its PO', function () {
    expect($this->workspace()->invoiceOverTolerance(brightline()))->toBeTrue()
        ->and(brightline()['variance_minor'])->toBe(125_000);
});

it('needs a note before an over-tolerance invoice becomes Actual (rule 11)', function () {
    app(ApproveSupplierInvoice::class)->handle(persona('grace'), brightline()['id']);
})->throws(ValidationException::class, 'over tolerance');

it('moves the PO amount from committed to the invoice amount in actual (rule 12)', function () {
    $before = $this->workspace()->financials(7);
    expect($before->health)->toBe(HealthStatus::OnTrack);

    $movement = app(ApproveSupplierInvoice::class)->handle(persona('grace'), brightline()['id'], 'Extra LED processor agreed on site.');

    $after = $this->workspace()->financials(7);
    expect($movement->minor)->toBe(125_000)
        ->and($before->committed->minus($after->committed)->minor)->toBe(1_840_000)
        ->and($after->actual->minus($before->actual)->minor)->toBe(1_965_000)
        ->and($after->forecast()->minus($before->forecast())->minor)->toBe(125_000)
        ->and($after->health)->toBe(HealthStatus::Watch)
        ->and(brightline()['status'])->toBe('approved')
        ->and($this->workspace()->purchaseOrders()->get(brightline()['purchase_order_id'])['status'])->toBe('invoiced');
});

it('approves a within-tolerance invoice without a note', function () {
    $nlv = $this->workspace()->supplierInvoices()->firstWhere('invoice_number', 'NLV-3388');

    expect(app(ApproveSupplierInvoice::class)->handle(persona('grace'), $nlv['id'])->isZero())->toBeTrue();
});

it('lets producers approve invoices only on their own events', function () {
    $nlv = $this->workspace()->supplierInvoices()->firstWhere('invoice_number', 'NLV-3388');

    expect(persona('hannah')->can('supplier-invoice.approve', [$nlv]))->toBeTrue()
        ->and(persona('marcus')->can('supplier-invoice.approve', [$nlv]))->toBeFalse();

    app(ApproveSupplierInvoice::class)->handle(persona('marcus'), $nlv['id']);
})->throws(AuthorizationException::class);

it('stores foreign costs with the original amount, rate and GBP equivalent (rule 16)', function () {
    $venue = $this->workspace()->costEntries(9)->first(fn ($c) => $c['currency'] === 'EUR');

    expect($venue['fx_rate'])->toBe('0.8650')
        ->and(Money::of($venue['amount_minor'], 'EUR')->convert($venue['fx_rate'], 'GBP')->minor)->toBe($venue['amount_gbp_minor']);
});

it('keeps every financial summary equal to the sum of its lines (rule 15)', function () {
    foreach ([3, 4, 5, 7, 8, 9, 10, 11, 12] as $eventId) {
        $lines = $this->workspace()->budgetLines($eventId);
        $f = $this->workspace()->financials($eventId);

        expect($f->budget->minor)->toBe($lines->sum(fn ($l) => $l['budget']->minor))
            ->and($f->forecast()->minor)->toBe($lines->sum(fn ($l) => $l['forecast']->minor));
    }
});

it('reports the seeded health of each live event', function () {
    expect($this->workspace()->health(4))->toBe(HealthStatus::AtRisk)
        ->and($this->workspace()->health(5))->toBe(HealthStatus::Watch)
        ->and($this->workspace()->health(7))->toBe(HealthStatus::OnTrack)
        ->and($this->workspace()->health(1))->toBeNull();
});
