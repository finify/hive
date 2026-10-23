<?php

use App\Http\Controllers\CallSheetController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\InvitationController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::view('/privacy', 'privacy')->name('privacy');

Route::middleware('guest')->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');
});

Route::middleware('auth')->group(function () {
    // Reachable before two-factor is set up, so required roles can enrol.
    Route::get('/settings/security', Livewire\Settings\Security::class)->name('security');

    Route::post('/demo/switch', [DemoController::class, 'switch'])->name('demo.switch');
    Route::post('/demo/reset', [DemoController::class, 'reset'])->name('demo.reset');

    Route::middleware('two-factor.required')->group(function () {
        Route::get('/dashboard', Livewire\Dashboard::class)->name('dashboard');
        Route::get('/my-work', Livewire\MyWork::class)->name('my-work');
        Route::get('/notifications', Livewire\Notifications::class)->name('notifications');

        Route::get('/events', Livewire\Events\Index::class)->name('events.index')->middleware('can:events.view');
        Route::get('/events/pipeline', Livewire\Events\Pipeline::class)->name('events.pipeline')->middleware('can:events.view_all');
        Route::get('/events/calendar', Livewire\Events\Calendar::class)->name('events.calendar')->middleware('can:events.view');
        Route::get('/events/new', Livewire\Events\Form::class)->name('events.create')->middleware('can:events.create');
        Route::get('/events/{code}/edit', Livewire\Events\Form::class)->name('events.edit')->middleware('can:events.edit');
        Route::get('/events/{code}/call-sheets/{date}', CallSheetController::class)->name('call-sheets.show');
        Route::get('/events/{code}', Livewire\Events\Show::class)->name('events.show')->middleware('can:events.view');

        Route::get('/resourcing/rota', Livewire\Resourcing\Rota::class)->name('rota')->middleware('can:rota.view');
        Route::get('/resourcing/crew-calls', Livewire\Resourcing\CrewCalls::class)->name('crew-calls')->middleware('can:crew.view');
        Route::get('/resourcing/availability', Livewire\Resourcing\Availability::class)->name('availability')->middleware('can:crew.manage');
        Route::get('/resourcing/clashes', Livewire\Resourcing\Clashes::class)->name('clashes')->middleware('can:rota.view_all');

        Route::get('/time/timesheets', Livewire\Time\Timesheets::class)->name('timesheets')->middleware('can:timesheets.submit');
        Route::get('/time/payroll-locks', Livewire\Time\PeriodLocks::class)->name('period-locks')->middleware('can:timesheets.lock');
        Route::get('/time/approvals', Livewire\Time\Approvals::class)->name('approvals')
            ->middleware('can_any:timesheets.approve,supplier_invoices.approve,leave.approve');

        Route::get('/finance/budgets', Livewire\Finance\Budgets::class)->name('budgets')->middleware('can:budgets.view');
        Route::get('/finance/purchase-orders', Livewire\Finance\PurchaseOrders::class)->name('purchase-orders')->middleware('can:po.view');
        Route::get('/finance/supplier-invoices', Livewire\Finance\SupplierInvoices::class)->name('supplier-invoices')->middleware('can:supplier_invoices.view');
        Route::get('/finance/expenses', Livewire\Finance\Expenses::class)->name('expenses')
            ->middleware('can_any:expenses.view_all,expenses.submit,expenses.approve');
        Route::get('/finance/billing', Livewire\Finance\Billing::class)->name('billing')->middleware('can:billing.view');

        Route::get('/people', Livewire\People\Directory::class)->name('people.index');
        Route::get('/people/new', Livewire\People\Form::class)->name('people.create');
        Route::get('/people/{person}/edit', Livewire\People\Form::class)->name('people.edit')->whereNumber('person');
        Route::get('/documents/{document}/download', DocumentDownloadController::class)->name('documents.download')->middleware('signed');
        Route::get('/people/{person}', Livewire\People\Show::class)->name('people.show')->whereNumber('person');
        Route::get('/people/leave', Livewire\People\Leave::class)->name('leave')->middleware('can_any:leave.view_calendar,leave.request');
        Route::get('/people/documents', Livewire\People\Documents::class)->name('documents')->middleware('can_any:documents.view,documents.view_status');

        Route::get('/directory/clients', Livewire\Directory\Clients::class)->name('clients')->middleware('can:directory.view');
        Route::get('/directory/clients/new', Livewire\Directory\ClientForm::class)->name('clients.create');
        Route::get('/directory/clients/{client}/edit', Livewire\Directory\ClientForm::class)->name('clients.edit');
        Route::get('/directory/suppliers', Livewire\Directory\Suppliers::class)->name('suppliers')->middleware('can:directory.view');
        Route::get('/directory/suppliers/new', Livewire\Directory\SupplierForm::class)->name('suppliers.create');
        Route::get('/directory/suppliers/{supplier}/edit', Livewire\Directory\SupplierForm::class)->name('suppliers.edit');
        Route::get('/directory/venues', Livewire\Directory\Venues::class)->name('venues')->middleware('can:directory.view');
        Route::get('/directory/venues/new', Livewire\Directory\VenueForm::class)->name('venues.create');
        Route::get('/directory/venues/{venue}/edit', Livewire\Directory\VenueForm::class)->name('venues.edit');

        Route::get('/admin/users', Livewire\Admin\Users::class)->name('admin.users')->middleware('can:users.manage');
        Route::get('/admin/audit', Livewire\Admin\AuditLog::class)->name('admin.audit')->middleware('can:audit.view');

        Route::get('/later/{module}', Livewire\Planned::class)->name('planned');
    });
});
