<?php

use App\Demo\Workspace;
use App\Enums\Role;
use App\Livewire\Resourcing\ResolveClashModal;
use App\Livewire\Resourcing\Rota;
use App\Models\User;
use Livewire\Livewire;

it('outlines the clash on the rota', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(Rota::class)
        ->assertSee('1 clash in this view')
        ->assertSeeHtml('is-clash')
        ->assertSee('Kofi Mensah');
});

it('filters the rota by department and type', function () {
    Livewire::actingAs(persona('tomasz'))
        ->test(Rota::class)
        ->set('type', 'staff')
        ->assertDontSee('Kofi Mensah')
        ->assertSee('Mei Lin Chow');
});

it('shows leave types only to those allowed to know them', function () {
    Livewire::actingAs(persona('hannah'))->test(Rota::class, ['start' => '2026-10-12', 'bookedOnly' => false])
        ->assertSee('Absent')->assertDontSee('Sick');
});

it('resolves the clash from the rota with a suggested person (phase 3)', function () {
    $key = app(Workspace::class)->clashes()[0]->key();

    Livewire::actingAs(persona('hannah'))
        ->test(ResolveClashModal::class)
        ->call('open', $key)
        ->assertSee('Kofi Mensah is booked twice on 23 Oct 2026')
        ->assertSee('Hollie Sutton')
        ->set('replacementId', 30)
        ->assertSee('Move 23 Oct 2026 to Hollie Sutton')
        ->call('resolve')
        ->assertHasNoErrors()
        ->assertDispatched('clash-resolved')
        ->assertDispatched('toast', message: 'Clash resolved. Kofi Mensah and Hollie Sutton have both been told.');

    Livewire::actingAs(persona('hannah'))->test(Rota::class)->assertSee('No clashes in this view');
});

it('needs a replacement to be chosen', function () {
    $key = app(Workspace::class)->clashes()[0]->key();

    Livewire::actingAs(persona('hannah'))
        ->test(ResolveClashModal::class)
        ->call('open', $key)
        ->call('resolve')
        ->assertHasErrors('replacementId');
});

it('tells people who cannot move either booking why', function () {
    $key = app(Workspace::class)->clashes()[0]->key();
    $chloe = User::factory()->withRole(Role::Producer)->create(['person_id' => 6]);

    Livewire::actingAs($chloe)->test(ResolveClashModal::class)->call('open', $key)
        ->assertSee('Only the producers of these events or the operations director can move these bookings.');
});
