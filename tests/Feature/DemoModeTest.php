<?php

use App\Demo\Workspace;

it('switches persona without losing demo changes', function () {
    $this->actingAs(persona('hannah'));
    app(Workspace::class)->ledger->put('notifications_read', 1, true);

    $this->post(route('demo.switch'), ['email' => 'grace@lumenco.test'])->assertRedirect(route('supplier-invoices'));

    $this->assertAuthenticatedAs(persona('grace'));
    expect(app(Workspace::class)->ledger->count())->toBe(1);
});

it('only switches to listed personas', function () {
    $this->actingAs(persona('hannah'))
        ->post(route('demo.switch'), ['email' => 'someone.else@lumenco.test'])
        ->assertNotFound();
});

it('does not switch personas outside demo mode', function () {
    config(['hive.demo.enabled' => false]);

    $this->actingAs(persona('kofi'))
        ->post(route('demo.switch'), ['email' => 'sam@lumenco.test'])
        ->assertNotFound();
    $this->assertAuthenticatedAs(persona('kofi'));
});

it('resets demo changes', function () {
    $this->actingAs(persona('hannah'));
    app(Workspace::class)->ledger->put('notifications_read', 1, true);

    $this->post(route('demo.reset'))->assertRedirect();

    expect(app(Workspace::class)->ledger->count())->toBe(0);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'demo', 'event' => 'reset']);
});

it('shows the demo band only in demo mode', function () {
    $this->actingAs(persona('hannah'))->get('/dashboard')->assertSee('Switch persona');

    config(['hive.demo.enabled' => false]);
    $this->actingAs(persona('hannah'))->get('/dashboard')->assertDontSee('Switch persona');
});
