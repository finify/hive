<?php

use App\Actions\Integrations\SyncAccounting;
use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Services\Accounting\XeroGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['hive.integrations.xero.client_id' => 'xero-id', 'hive.integrations.xero.client_secret' => 'xero-secret']);
});

it('does nothing until Xero is connected', function () {
    config(['hive.integrations.xero.client_id' => null]);
    Http::fake();

    expect(app(SyncAccounting::class)->handle())->toBe(['pushed' => 0, 'paid' => 0, 'failed' => 0]);
    Http::assertNothingSent();
});

it('pushes each approved invoice once as a coded bill, then records Xero payments', function () {
    $approved = SupplierInvoice::query()->where('status', SupplierInvoiceStatus::Approved)->get();
    expect($approved)->not->toBeEmpty();

    $n = 0;
    Http::fake([
        'identity.xero.com/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 1800]),
        'api.xero.com/api.xro/2.0/Invoices?*' => Http::response(['Invoices' => [['InvoiceID' => 'xero-1', 'Status' => 'PAID', 'FullyPaidOnDate' => '/Date(1792800000000+0000)/']]]),
        'api.xero.com/api.xro/2.0/Invoices' => function () use (&$n) {
            $n++;

            return Http::response(['Invoices' => [['InvoiceID' => 'xero-'.$n]]]);
        },
    ]);

    $result = app(SyncAccounting::class)->handle();

    expect($result['pushed'])->toBe($approved->count())
        ->and($result['paid'])->toBe(1)
        ->and(SupplierInvoice::query()->where('accounting_id', 'xero-1')->sole()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and(Activity::query()->where('event', 'bill-pushed')->count())->toBe($approved->count());

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/Invoices') && $r->method() === 'POST'
        && $r['Invoices'][0]['Type'] === 'ACCPAY' && $r['Invoices'][0]['Status'] === 'AUTHORISED'
        && preg_match('/^5\d{3}$/', $r['Invoices'][0]['LineItems'][0]['AccountCode']) === 1
        && $r->hasHeader('Authorization', 'Bearer tok'));

    // Already pushed: a second run sends no new bills.
    $before = $n;
    app(SyncAccounting::class)->handle();
    expect($n)->toBe($before);
});

it('keeps going when one bill fails', function () {
    Http::fake([
        'identity.xero.com/connect/token' => Http::response(['access_token' => 'tok']),
        'api.xero.com/api.xro/2.0/Invoices' => Http::response(['Message' => 'A validation exception occurred'], 400),
        'api.xero.com/api.xro/2.0/Invoices?*' => Http::response(['Invoices' => []]),
    ]);

    $result = app(SyncAccounting::class)->handle();

    expect($result['pushed'])->toBe(0)
        ->and($result['failed'])->toBeGreaterThan(0)
        ->and(SupplierInvoice::query()->whereNotNull('accounting_id')->count())->toBe(0);
});

it('reads Xero’s date format', function () {
    expect(XeroGateway::date('/Date(1792800000000+0000)/'))->toBe('2026-10-24')
        ->and(XeroGateway::date('2026-10-01T00:00:00'))->toBe('2026-10-01')
        ->and(XeroGateway::date(''))->toBeNull();
});
