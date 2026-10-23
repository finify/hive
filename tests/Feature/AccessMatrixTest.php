<?php

/*
 * Section 6 of the specification, as routes. 200 means the role may open the
 * screen; 403 or 404 means it may not. Every sensitive screen is covered.
 */

dataset('screens', function () {
    $matrix = [
        // path                       priya hannah grace leah tomasz jamie kofi  sam
        '/dashboard' => [200,  200,   200,  200, 200,   200,  200,  200],
        '/events' => [200,  200,   200,  200, 200,   200,  200,  200],
        '/events/pipeline' => [200,  200,   200,  200, 200,   403,  403,  200],
        '/events/EV-26104' => [200,  200,   200,  200, 200,   404,  404,  200],
        '/resourcing/rota' => [200,  200,   200,  200, 200,   200,  200,  200],
        '/resourcing/availability' => [200, 200,   403,  403, 200,   403,  403,  200],
        '/resourcing/clashes' => [200,  200,   200,  200, 200,   403,  403,  200],
        '/time/timesheets' => [403,  200,   403,  403, 200,   200,  200,  200],
        '/time/approvals' => [200,  200,   200,  200, 200,   403,  403,  200],
        '/finance/budgets' => [200,  200,   200,  403, 403,   403,  403,  200],
        '/finance/supplier-invoices' => [200, 200, 200,  403, 403,   403,  403,  200],
        '/finance/billing' => [200,  403,   200,  403, 403,   403,  403,  200],
        '/people' => [200,  200,   200,  200, 200,   200,  403,  200],
        '/people/documents' => [403,  200,   200,  200, 403,   403,  403,  200],
        '/directory/clients' => [200,  200,   200,  200, 200,   200,  403,  200],
        '/admin/users' => [403,  403,   403,  200, 403,   403,  403,  200],
        '/admin/audit' => [200,  403,   200,  200, 403,   403,  403,  200],
    ];
    $people = ['priya', 'hannah', 'grace', 'leah', 'tomasz', 'jamie', 'kofi', 'sam'];

    foreach ($matrix as $path => $statuses) {
        foreach ($people as $i => $who) {
            yield "{$who} {$path}" => [$who, $path, $statuses[$i]];
        }
    }
});

it('enforces the permissions matrix', function (string $who, string $path, int $status) {
    $this->actingAs(persona($who))->get($path)->assertStatus($status);
})->with('screens');

it('hides menu items the user may not open', function () {
    $this->actingAs(persona('kofi'))->get('/my-work')
        ->assertSee('My timesheets')
        ->assertDontSee('Budgets')
        ->assertDontSee('Users and roles')
        ->assertDontSee('Audit log');

    $this->actingAs(persona('grace'))->get('/finance/budgets')
        ->assertSee('Supplier invoices')
        ->assertDontSee('Users and roles');
});
