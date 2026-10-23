<?php

use App\Demo\Workspace;
use App\Livewire\Finance\SupplierInvoices;
use Livewire\Livewire;

it('approves an over-tolerance invoice once a reason is given', function () {
    $id = app(Workspace::class)->supplierInvoices()->firstWhere('invoice_number', 'BL-20931')['id'];

    Livewire::actingAs(persona('grace'))
        ->test(SupplierInvoices::class)
        ->call('review', $id)
        ->assertSee('Tolerance (5% or £250, whichever is lower)')
        ->assertSee('(25.1%) to')->assertSee('(24.9%)')
        ->call('approve')
        ->assertHasErrors('note')
        ->set('note', 'Extra LED processor agreed on site for the hybrid stream.')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Approved. Forecast moved by £1,250.00; margin 25.1% to 24.9%, now on watch.');
});

it('lets a producer view but not approve an invoice on another producer’s event', function () {
    $id = app(Workspace::class)->supplierInvoices()->firstWhere('invoice_number', 'MSS-1182')['id'];

    Livewire::actingAs(persona('marcus'))->test(SupplierInvoices::class, ['show' => 'all'])->assertSee('MSS-1182');
    Livewire::actingAs(persona('hannah'))->test(SupplierInvoices::class, ['show' => 'all'])->assertDontSee('MSS-1182');
});
