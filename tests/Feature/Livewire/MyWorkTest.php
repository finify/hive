<?php

use App\Demo\Workspace;
use App\Livewire\MyWork;
use App\Livewire\Time\Timesheets;
use Livewire\Livewire;

it('lets Kofi accept the Lisbon booking from his phone', function () {
    Livewire::actingAs(persona('kofi'))
        ->test(MyWork::class)
        ->assertSee('Halcyon Health European Congress')
        ->assertSee('£400.00')
        ->call('respond', app(Workspace::class)->assignments()->first(fn ($a) => $a['person_id'] === 26 && $a['event_id'] === 9)['id'], true)
        ->assertDispatched('toast', message: 'Booking accepted.')
        ->assertDontSee('Accept booking');
});

it('adds time and submits the week', function () {
    Livewire::actingAs(persona('kofi'))
        ->test(Timesheets::class)
        ->set('date', '2026-10-23')
        ->set('work', 'event:7')
        ->set('start_time', '07:30')
        ->set('end_time', '19:30')
        ->set('break_minutes', 60)
        ->call('addEntry')
        ->assertHasNoErrors()
        ->assertSee('Submit timesheet')
        ->call('submit', app(Workspace::class)->timesheets()->first(fn ($s) => $s['person_id'] === 26 && $s['week_start'] === '2026-10-19')['id'])
        ->assertDispatched('toast', message: 'Timesheet submitted. Each producer approves their part.');
});

it('shows validation next to the field', function () {
    Livewire::actingAs(persona('kofi'))
        ->test(Timesheets::class)
        ->set('work', '')
        ->call('addEntry')
        ->assertHasErrors('work')
        ->assertSee('Choose the event or internal code you worked on.');
});
