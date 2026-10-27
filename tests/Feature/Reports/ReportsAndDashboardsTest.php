<?php

use App\Livewire\Dashboard;
use App\Livewire\People\Skills;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\Show;
use App\Reports\Reports;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('shows each role its own dashboard and nothing it may not see (phase 6)', function (string $who, array $sees, array $never) {
    $page = Livewire::actingAs(persona($who))->test(Dashboard::class);

    foreach ($sees as $text) {
        $page->assertSee($text);
    }

    foreach ($never as $text) {
        $page->assertDontSee($text);
    }
})->with([
    'operations director' => ['priya', ['Pipeline (enquiries and proposals)', 'Forecast margin, active events', 'Budget health'], ['Leave left this year']],
    'finance' => ['grace', ['Committed in October', 'Invoices to approve', 'POs awaiting approval'], ['Pipeline (enquiries and proposals)', 'Onboarding and offboarding']],
    'hr' => ['leah', ['Starters and leavers, next six weeks', 'Joel Mendes starts', 'Liam Walsh leaves', 'Onboarding and offboarding'], ['Budget health', 'Forecast margin', 'Committed in October']],
    'producer' => ['hannah', ['My events in progress', 'Open crew calls', 'Entries to approve'], ['Pipeline (enquiries and proposals)', 'Committed in October']],
    'head of department' => ['tomasz', ['Needs attention'], ['Budget health', 'Forecast margin', '£']],
    'employee' => ['jamie', ['Leave left this year', '19 days', 'plus 1.5 days TOIL'], ['Budget health', '£', 'Starters and leavers']],
    'freelancer' => ['kofi', ['Booking requests', 'Next booking'], ['Budget health', 'Leave left this year', 'Starters and leavers']],
]);

it('lists only the reports each role may run', function (string $who, array $keys) {
    expect(array_map(fn ($r) => $r::key(), Reports::for(persona($who))))->toBe($keys);
})->with([
    'operations director' => ['priya', ['event-profitability', 'utilisation', 'freelancer-spend', 'supplier-spend', 'pipeline', 'leave', 'timesheet-compliance', 'working-time-flags']],
    'finance' => ['grace', ['event-profitability', 'utilisation', 'freelancer-spend', 'supplier-spend', 'pipeline', 'timesheet-compliance', 'working-time-flags']],
    'hr' => ['leah', ['utilisation', 'freelancer-spend', 'pipeline', 'leave', 'timesheet-compliance', 'working-time-flags']],
    'producer' => ['hannah', ['event-profitability', 'utilisation', 'supplier-spend', 'pipeline']],
    'head of department' => ['tomasz', ['utilisation', 'pipeline']],
]);

it('keeps reports away from people without reports.view', function (string $who) {
    $this->actingAs(persona($who))->get(route('reports'))->assertForbidden();
    $this->actingAs(persona($who))->get(route('reports.csv', 'utilisation'))->assertForbidden();
})->with(['jamie', 'kofi']);

it('exports every report to CSV with its columns and totals, and logs it (phase 6)', function () {
    $sam = persona('sam');

    foreach (Reports::for($sam) as $report) {
        $response = $this->actingAs($sam)->get(route('reports.csv', $report::key()));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        expect($lines[0])->toBe(array_map(fn ($c) => $c['label'].(($c['type'] ?? '') === 'money' ? ' (GBP)' : ''), $report->columns()))
            ->and(count($lines))->toBeGreaterThan(1);
    }

    expect(Activity::query()->where('event', 'report-exported')->count())->toBe(count(Reports::ALL));
});

it('adds up event profitability from the rows shown', function () {
    $report = Reports::find(persona('priya'), 'event-profitability');
    $rows = $report->rows($report->resolve([]));
    $csv = $report->csv($report->resolve([]));

    expect(end($csv)[0])->toBe('Total')
        ->and($report->totals($rows)['margin'])->toBe(array_sum(array_column($rows, 'margin')))
        ->and(collect($rows)->pluck('code'))->toContain('EV-26104');
});

it('shows client fees in the pipeline only to people who may see budgets', function () {
    $tomasz = Reports::find(persona('tomasz'), 'pipeline');
    $priya = Reports::find(persona('priya'), 'pipeline');

    expect(collect($tomasz->rows([]))->pluck('value')->filter()->all())->toBe([])
        ->and(collect($priya->rows([]))->pluck('value')->filter()->all())->not->toBe([])
        ->and($priya->totals($priya->rows([]))['share'])->toBeGreaterThan(0);
});

it('shows sickness days in the leave report to HR only', function () {
    expect(collect(Reports::find(persona('leah'), 'leave')->columns())->pluck('key'))->toContain('sick')
        ->and(collect(Reports::find(persona('priya'), 'leave')->columns())->pluck('key'))->not->toContain('sick');
});

it('filters a report, ignoring values it doesn’t offer', function () {
    Livewire::actingAs(persona('priya'))->test(Show::class, ['key' => 'utilisation'])
        ->set('filters.by', 'department')
        ->assertSee('Technical')
        ->assertSee('people')
        ->set('filters.by', 'nonsense')
        ->assertSee('Freelancer');

    Livewire::actingAs(persona('priya'))->test(Index::class)->assertSee('Working time flags');
    $this->actingAs(persona('hannah'))->get(route('reports.show', 'leave'))->assertNotFound();
});

it('lists the skills catalogue with levels and verification', function () {
    Livewire::actingAs(persona('hannah'))->test(Skills::class)
        ->assertSee('Skills')
        ->set('search', 'disguise')
        ->assertSee('Disguise')
        ->assertDontSee('Rigging');
});
