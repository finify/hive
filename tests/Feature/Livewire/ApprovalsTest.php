<?php

use App\Demo\Insights;
use App\Livewire\Time\Approvals;
use Livewire\Livewire;

it('shows Hannah her entries with the long-shift flag', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(Approvals::class)
        ->set('tab', 'timesheets')
        ->assertSee('Halcyon Health Clinical Showcase')
        ->assertSee('Approve 9 entries')
        ->assertSee('14-hour shift, over the 12-hour limit')
        ->assertDontSee('Arclight');
});

it('approves a group and posts crew cost', function () {
    $this->actingAs(persona('hannah'));
    $ids = app(Insights::class)->pendingEntries()->get('EV-26105')['items'];
    $ids = collect($ids)->pluck('entry.id')->all();

    Livewire::actingAs(persona('hannah'))
        ->test(Approvals::class)
        ->set('tab', 'timesheets')
        ->call('approveMany', $ids)
        ->assertDispatched('toast', message: '9 entries approved. Crew costs posted to the event budget.')
        ->assertSee('No timesheets to approve');
});

it('needs a comment to return an entry', function () {
    $this->actingAs(persona('hannah'));
    $entry = collect(app(Insights::class)->pendingEntries()->get('EV-26105')['items'])->first()['entry'];

    Livewire::actingAs(persona('hannah'))
        ->test(Approvals::class)
        ->call('startReturn', $entry['id'])
        ->set('returnComment', '')
        ->call('confirmReturn')
        ->assertHasErrors('comment')
        ->set('returnComment', 'Your finish time looks wrong, please check.')
        ->call('confirmReturn')
        ->assertHasNoErrors();
});

it('warns before approving leave that clashes with a booking', function () {
    // Freya Collins asks for 4–6 Nov; she is booked on EV-26118 on 5 Nov.
    Livewire::actingAs(persona('leah'))
        ->test(Approvals::class, ['tab' => 'leave'])
        ->assertSee('Freya Collins')
        ->assertSee('Approving creates a clash')
        ->assertSee('Approve anyway');
});
