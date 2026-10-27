<?php

use Illuminate\Support\Facades\DB;

/**
 * Section 14: no list page runs more than 15 queries. Measured with the
 * Workspace cache warm, as it is for everyone but the first visitor after
 * a change.
 */
function queriesFor(string $url): int
{
    test()->get($url)->assertOk();
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps every list page within 15 queries', function (string $who, string $url) {
    $this->actingAs(persona($who));

    expect(queriesFor($url))->toBeLessThanOrEqual(15);
})->with([
    'producer' => 'hannah',
    'super admin' => 'sam',
])->with([
    '/my-work', '/notifications', '/events', '/events/pipeline', '/events/calendar', '/events/EV-26114', '/events/EV-26114?tab=crew',
    '/events/EV-26114?tab=budget', '/events/EV-26117?tab=travel', '/resourcing/rota', '/resourcing/crew-calls', '/resourcing/availability',
    '/resourcing/clashes', '/time/timesheets', '/time/approvals', '/finance/budgets', '/finance/purchase-orders', '/finance/supplier-invoices',
    '/finance/freelancer-invoices', '/finance/expenses', '/people', '/people/leave', '/people/skills', '/people/26',
    '/directory/clients', '/directory/suppliers', '/directory/venues', '/reports', '/reports/event-profitability', '/reports/utilisation',
]);

it('keeps admin and finance list pages within 15 queries', function (string $url) {
    $this->actingAs(persona('sam'));

    expect(queriesFor($url))->toBeLessThanOrEqual(15);
})->with(['/finance/billing', '/people/documents', '/people/onboarding', '/admin/users', '/admin/audit', '/admin/integrations', '/admin/settings', '/admin/retention']);

it('keeps the busiest dashboard within 25 queries', function (string $who) {
    $this->actingAs(persona($who));

    expect(queriesFor('/dashboard'))->toBeLessThanOrEqual(25);
})->with(['sam', 'priya', 'grace', 'leah', 'hannah', 'jamie', 'kofi']);
