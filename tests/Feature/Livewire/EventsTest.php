<?php

use App\Livewire\Events\Index;
use App\Livewire\Events\Pipeline;
use App\Livewire\Events\Show;
use Livewire\Livewire;

it('filters the events list', function () {
    Livewire::actingAs(persona('priya'))
        ->test(Index::class)
        ->assertSee('EV-26114')
        ->set('stage', 'enquiry')
        ->assertSee('EV-26126')
        ->assertDontSee('EV-26114')
        ->call('clearFilters')
        ->set('country', 'Portugal')
        ->assertSee('Halcyon Health European Congress')
        ->assertDontSee('Northwind Systems Partner Summit');
});

it('blocks a drag to Confirmed without a signed contract, and explains', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(Pipeline::class)
        ->call('move', 'EV-26123', 'confirmed')
        ->assertSet('blockers', ['Upload the signed contract.'])
        ->assertDispatched('modal', id: 'blocked-modal', show: true);
});

it('moves a card when the rules allow', function () {
    Livewire::actingAs(persona('priya'))
        ->test(Pipeline::class)
        ->call('move', 'EV-26126', 'proposal-sent')
        ->assertDispatched('toast', message: 'EV-26126 moved to Proposal sent.');
});

it('shows the budget tab only to people who may see it', function () {
    Livewire::actingAs(persona('hannah'))->test(Show::class, ['code' => 'EV-26114'])->set('tab', 'budget')->assertSee('Cost lines');
    Livewire::actingAs(persona('jamie'))->test(Show::class, ['code' => 'EV-26114'])->set('tab', 'budget')->assertDontSee('Cost lines')->assertSet('tab', 'overview');
});

it('asks for confirmation before cancelling, then releases bookings', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(Show::class, ['code' => 'EV-26117'])
        ->call('prepareStage', 'cancelled')
        ->assertSee('Cancelling releases every booking')
        ->set('stageNote', 'Client postponed')
        ->call('changeStage')
        ->assertDispatched('toast', message: 'Moved to Cancelled.');
});
