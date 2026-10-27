<?php

use Illuminate\Support\Facades\DB;

it('probes', function () {
    $urls = ['/dashboard', '/my-work', '/events', '/events/pipeline', '/events/EV-26114', '/events/EV-26114?tab=crew', '/events/EV-26114?tab=budget', '/events/EV-26117?tab=travel', '/resourcing/rota', '/resourcing/crew-calls', '/resourcing/availability', '/resourcing/clashes', '/time/timesheets', '/time/approvals', '/finance/budgets', '/finance/purchase-orders', '/finance/supplier-invoices', '/finance/freelancer-invoices', '/finance/expenses', '/people', '/people/leave', '/people/26', '/directory/clients', '/reports/event-profitability', '/reports/utilisation', '/admin/audit'];
    $out = [];
    foreach (['sam', 'hannah'] as $who) {
        foreach ($urls as $url) {
            $this->actingAs(persona($who));
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $s = $this->get($url)->status();
            $out[] = sprintf('%-7s %-32s %3d %3d', $who, $url, $s, count(DB::getQueryLog()));
            DB::disableQueryLog();
        }
    }
    fwrite(STDERR, implode("\n", $out)."\n");
    expect(true)->toBeTrue();
});
