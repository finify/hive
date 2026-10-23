<?php

namespace App\Providers;

use App\Demo\DemoData;
use App\Demo\DemoLedger;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Models\User;
use App\Policies\AssignmentPolicy;
use App\Policies\EventPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\SupplierInvoicePolicy;
use App\Policies\TimesheetEntryPolicy;
use App\Services\AskHive\AskHiveEngine;
use App\Services\AskHive\LocalEngine;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(DemoData::class, fn () => DemoData::fromConfig());

        $this->app->scoped(Workspace::class, fn ($app) => new Workspace(
            $app->make(DemoData::class),
            new DemoLedger($app['session.store']),
        ));

        $this->app->bind(AskHiveEngine::class, LocalEngine::class);

        $this->app->bind(Viewer::class, function ($app) {
            /** @var User $user */
            $user = Auth::user();

            return new Viewer($user, $app->make(Workspace::class));
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::define('timesheet-entry.approve', [TimesheetEntryPolicy::class, 'approve']);
        Gate::define('assignment.respond', [AssignmentPolicy::class, 'respond']);
        Gate::define('assignment.reassign', [AssignmentPolicy::class, 'reassign']);
        Gate::define('supplier-invoice.approve', [SupplierInvoicePolicy::class, 'approve']);
        Gate::define('event.change-stage', [EventPolicy::class, 'changeStage']);
        Gate::define('leave.approve-request', [LeaveRequestPolicy::class, 'approve']);

        $this->logSignIns();
    }

    /**
     * Every sign-in and failed sign-in goes to the audit trail.
     */
    private function logSignIns(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if (! $event->user instanceof User) {
                return;
            }

            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            activity('identity')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->withProperties(['ip' => request()->ip()])
                ->event('signed-in')
                ->log('Signed in');
        });

        Event::listen(Failed::class, function (Failed $event) {
            activity('identity')
                ->withProperties(['email' => $event->credentials['email'] ?? null, 'ip' => request()->ip()])
                ->event('sign-in-failed')
                ->log('Failed sign-in');
        });
    }
}
