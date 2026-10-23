<?php

use App\Demo\Viewer;
use App\Demo\Workspace;

function viewerFor(string $who): Viewer
{
    return new Viewer(persona($who), app(Workspace::class));
}

it('strips rates and restricted fields from people for those without permission (rule 17)', function () {
    $kofi = app(Workspace::class)->person(26);

    $asProducer = viewerFor('hannah')->presentPerson($kofi);
    $asHr = viewerFor('leah')->presentPerson($kofi);
    $asSelf = viewerFor('kofi')->presentPerson($kofi);

    expect($asProducer)->not->toHaveKeys(['day_rates', 'restricted', 'phone', 'notes', 'vat_number'])
        ->and($asHr)->toHaveKeys(['day_rates', 'restricted'])
        ->and($asSelf)->toHaveKeys(['day_rates', 'restricted']);
});

it('creates a freelancer with skills, a rate and insurance that a producer cannot see the rate of (phase 1)', function () {
    $this->actingAs(persona('leah'))->get('/people/26?tab=rates')->assertOk()->assertSee('£400.00')->assertSee('£380.00');
    $this->actingAs(persona('leah'))->get('/people/26?tab=documents')->assertOk()->assertSee('Public liability insurance £5m');

    $this->actingAs(persona('hannah'))->get('/people/26?tab=skills')->assertOk()->assertSee('Disguise media server');

    $this->actingAs(persona('hannah'))->get('/people/26')->assertOk()
        ->assertDontSee('Rates')
        ->assertDontSee('Restricted')
        ->assertDontSee('Mensah Media Ltd');

    $this->actingAs(persona('hannah'))->get('/people/26?tab=rates')->assertOk()->assertDontSee('£400.00')->assertDontSee('£380.00');
    $this->actingAs(persona('hannah'))->get('/people/27?tab=restricted')->assertOk()->assertDontSee('Emergency contact');
});

it('never renders agreed rates on the crew tab for producers (rule 17)', function () {
    $this->actingAs(persona('hannah'))->get('/events/EV-26114?tab=crew')->assertOk()->assertDontSee('Agreed day rate');
    $this->actingAs(persona('grace'))->get('/events/EV-26114?tab=crew')->assertOk()->assertSee('Agreed day rate');
});

it('shows sensitive leave as Absent except to HR, the manager and the person', function () {
    $sick = app(Workspace::class)->leaveRequests()->get(3); // Mei Lin Chow, sick

    expect(viewerFor('hannah')->leaveLabel($sick))->toBe('Absent')
        ->and(viewerFor('leah')->leaveLabel($sick))->toBe('Sick')
        ->and(viewerFor('tomasz')->leaveLabel($sick))->toBe('Sick');
});

it('shows freelancers only their own events, rota and profile (rule 18)', function () {
    $events = viewerFor('kofi')->events();

    expect($events->pluck('code')->sort()->values()->all())->toBe(['EV-26105', 'EV-26114', 'EV-26116', 'EV-26117'])
        ->and(viewerFor('kofi')->rotaPeople())->toBe([26]);

    $this->actingAs(persona('kofi'))->get('/resourcing/rota')->assertOk()->assertSee('Kofi Mensah')->assertDontSee('Arjun Mehta');
    $this->actingAs(persona('kofi'))->get('/people/31')->assertNotFound();
    $this->actingAs(persona('kofi'))->get('/events/EV-26104')->assertNotFound();
    $this->actingAs(persona('kofi'))->get('/events')->assertOk()->assertDontSee('Meridian Bank Leadership Forum')->assertDontSee('£486,000');
});

it('lets producers see budgets only for events they produce', function () {
    $this->actingAs(persona('marcus'))->get('/events/EV-26114?tab=budget')->assertOk()->assertDontSee('Cost lines');
    $this->actingAs(persona('hannah'))->get('/events/EV-26114?tab=budget')->assertOk()->assertSee('Cost lines');
});
