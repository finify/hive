<?php

use App\Livewire\Directory\ClientForm;
use App\Livewire\Directory\SupplierForm;
use App\Livewire\Directory\VenueForm;
use App\Models\Client;
use App\Models\Supplier;
use Livewire\Livewire;

it('lets directory.manage add and edit clients, suppliers and venues', function (string $who, int $status) {
    foreach (['/directory/clients/new', '/directory/suppliers/new', '/directory/venues/new', '/directory/clients/1/edit'] as $path) {
        $this->actingAs(persona($who))->get($path)->assertStatus($status);
    }
})->with([['hannah', 200], ['grace', 200], ['priya', 200], ['leah', 403], ['jamie', 403], ['kofi', 403]]);

it('adds a client with contacts and one primary', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(ClientForm::class)
        ->set('form.name', 'Kestrel Robotics')
        ->set('form.sector', 'Engineering')
        ->set('contacts.0.name', 'Maya Holt')
        ->set('contacts.0.email', 'maya@kestrel.example')
        ->call('addContact')
        ->set('contacts.1.name', 'Ravi Patel')
        ->set('contacts.1.is_primary', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('clients'));

    $client = Client::query()->where('name', 'Kestrel Robotics')->with('contacts')->firstOrFail();
    expect($client->contacts->where('is_primary', true)->pluck('name')->all())->toBe(['Ravi Patel']);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'directory', 'subject_id' => $client->id]);
});

it('records a new supplier insurance date as a document', function () {
    $supplier = Supplier::query()->findOrFail(6);

    Livewire::actingAs(persona('grace'))
        ->test(SupplierForm::class, ['supplier' => $supplier])
        ->assertSet('form.insurance_expires_at', '2026-11-09')
        ->set('form.insurance_expires_at', '2027-11-09')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->workspace()->suppliers()->get(6)['insurance_expires_at'])->toBe('2027-11-09');
});

it('validates venues', function () {
    Livewire::actingAs(persona('hannah'))
        ->test(VenueForm::class)
        ->set('form.name', 'Riverside Convention Centre')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.address', 'form.city']);
});
