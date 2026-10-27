<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CallSheetController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\FinanceExportController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PersonDataExportController;
use App\Http\Controllers\PurchaseOrderPdfController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SingleSignOnController;
use App\Http\Controllers\TravelManifestController;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::view('/privacy', 'privacy')->name('privacy');

// Private calendar feeds: the token is the credential.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/calendar/{token}.ics', [CalendarFeedController::class, 'personal'])->name('calendar.personal');
    Route::get('/calendar/{token}/events/{code}.ics', [CalendarFeedController::class, 'event'])->name('calendar.event');
});

Route::middleware('guest')->group(function () {
    Route::get('/auth/microsoft', [SingleSignOnController::class, 'redirect'])->middleware('throttle:20,1')->name('sso.redirect');
    Route::get('/auth/microsoft/callback', [SingleSignOnController::class, 'callback'])->middleware('throttle:20,1')->name('sso.callback');
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
        Route::get('/help', Livewire\Help\Index::class)->name('help');
        Route::get('/help/{key}', Livewire\Help\Show::class)->name('help.show');
        Route::get('/settings/notifications', Livewire\Settings\Notifications::class)->name('notification-settings');

        Route::get('/events', Livewire\Events\Index::class)->name('events.index')->middleware('can:events.view');
        Route::get('/events/pipeline', Livewire\Events\Pipeline::class)->name('events.pipeline')->middleware('can:events.view_all');
        Route::get('/events/calendar', Livewire\Events\Calendar::class)->name('events.calendar')->middleware('can:events.view');
        Route::get('/events/new', Livewire\Events\Form::class)->name('events.create')->middleware('can:events.create');
        Route::get('/events/{code}/edit', Livewire\Events\Form::class)->name('events.edit')->middleware('can:events.edit');
        Route::get('/events/{code}/call-sheets/{date}', CallSheetController::class)->name('call-sheets.show');
        Route::get('/events/{code}/travel-manifest.csv', TravelManifestController::class)->name('travel.manifest');
        Route::get('/events/{code}', Livewire\Events\Show::class)->name('events.show')->middleware('can:events.view');

        Route::get('/resourcing/rota', Livewire\Resourcing\Rota::class)->name('rota')->middleware('can:rota.view');
        Route::get('/resourcing/crew-calls', Livewire\Resourcing\CrewCalls::class)->name('crew-calls')->middleware('can:crew.view');
        Route::get('/resourcing/availability', Livewire\Resourcing\Availability::class)->name('availability')->middleware('can:crew.manage');
        Route::get('/resourcing/clashes', Livewire\Resourcing\Clashes::class)->name('clashes')->middleware('can:rota.view_all');

        Route::get('/time/timesheets', Livewire\Time\Timesheets::class)->name('timesheets')->middleware('can:timesheets.submit');
        Route::get('/time/payroll-locks', Livewire\Time\PeriodLocks::class)->name('period-locks')->middleware('can:timesheets.lock');
        Route::get('/time/approvals', Livewire\Time\Approvals::class)->name('approvals')
            ->middleware('can_any:timesheets.approve,supplier_invoices.approve,leave.approve,po.approve,expenses.approve,freelancer_invoices.approve');

        Route::get('/finance/budgets', Livewire\Finance\Budgets::class)->name('budgets')->middleware('can:budgets.view');
        Route::get('/finance/purchase-orders', Livewire\Finance\PurchaseOrders::class)->name('purchase-orders')->middleware('can:po.view');
        Route::get('/finance/purchase-orders/new', Livewire\Finance\PurchaseOrderForm::class)->name('purchase-orders.create')->middleware('can:po.create');
        Route::get('/finance/purchase-orders/{code}/edit', Livewire\Finance\PurchaseOrderForm::class)->name('purchase-orders.edit')->middleware('can:po.create');
        Route::get('/finance/purchase-orders/{code}/pdf', PurchaseOrderPdfController::class)->name('purchase-orders.pdf');
        Route::get('/finance/purchase-orders/{code}', Livewire\Finance\PurchaseOrderShow::class)->name('purchase-orders.show')->middleware('can:po.view');
        Route::get('/finance/freelancer-invoices', Livewire\Finance\FreelancerInvoices::class)->name('freelancer-invoices')
            ->middleware('can_any:freelancer_invoices.view,freelancer_invoices.submit');
        Route::get('/finance/exports', Livewire\Finance\Exports::class)->name('exports')->middleware('can:billing.view');
        Route::get('/finance/exports/{type}.csv', FinanceExportController::class)->name('exports.download')->middleware('can:billing.view');
        Route::get('/finance/supplier-invoices', Livewire\Finance\SupplierInvoices::class)->name('supplier-invoices')->middleware('can:supplier_invoices.view');
        Route::get('/finance/expenses', Livewire\Finance\Expenses::class)->name('expenses')
            ->middleware('can_any:expenses.view_all,expenses.submit,expenses.approve');
        Route::get('/finance/billing', Livewire\Finance\Billing::class)->name('billing')->middleware('can:billing.view');

        Route::get('/people', Livewire\People\Directory::class)->name('people.index');
        Route::get('/people/new', Livewire\People\Form::class)->name('people.create');
        Route::get('/people/{person}/edit', Livewire\People\Form::class)->name('people.edit')->whereNumber('person');
        Route::get('/documents/{document}/download', DocumentDownloadController::class)->name('documents.download')->middleware('signed');
        Route::get('/people/{person}/export.zip', PersonDataExportController::class)->name('people.export')->whereNumber('person');
        Route::get('/people/{person}', Livewire\People\Show::class)->name('people.show')->whereNumber('person');
        Route::get('/people/leave', Livewire\People\Leave::class)->name('leave')->middleware('can_any:leave.view_calendar,leave.request');
        Route::get('/people/skills', Livewire\People\Skills::class)->name('skills')->middleware('can:people.view_basic');
        Route::get('/people/onboarding', Livewire\People\Onboarding::class)->name('onboarding')->middleware('can_any:people.manage,leave.approve,rates.edit,users.manage');
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

        Route::get('/reports', Livewire\Reports\Index::class)->name('reports')->middleware('can:reports.view');
        Route::get('/reports/{key}.csv', ReportExportController::class)->name('reports.csv')->middleware('can:reports.view');
        Route::get('/reports/{key}', Livewire\Reports\Show::class)->name('reports.show')->middleware('can:reports.view');

        Route::get('/admin/users', Livewire\Admin\Users::class)->name('admin.users')->middleware('can:users.manage');
        Route::get('/admin/integrations', Livewire\Admin\Integrations::class)->name('integrations')->middleware('can:settings.view');
        Route::get('/admin/import', Livewire\Admin\Import::class)->name('import')->middleware('can_any:people.manage,directory.manage');
        Route::get('/admin/import/{type}/template.csv', ImportTemplateController::class)->name('import.template')->middleware('can_any:people.manage,directory.manage');
        Route::get('/admin/settings', Livewire\Admin\Settings::class)->name('settings')->middleware('can:settings.view');
        Route::get('/admin/retention', Livewire\Admin\Retention::class)->name('retention')->middleware('can:people.manage');
        Route::get('/admin/queue', Livewire\Admin\Queue::class)->name('queue')->middleware('can:settings.view');
        Route::get('/admin/audit', Livewire\Admin\AuditLog::class)->name('admin.audit')->middleware('can:audit.view');

        Route::get('/later/{module}', Livewire\Planned::class)->name('planned');
    });
});
