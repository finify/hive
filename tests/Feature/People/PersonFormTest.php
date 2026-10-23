<?php

use App\Actions\People\SavePerson;
use App\Livewire\People\Form;
use App\Models\Person;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('only lets people.manage open the form', function (string $who, int $status) {
    $this->actingAs(persona($who))->get('/people/new')->assertStatus($status);
    $this->actingAs(persona($who))->get('/people/26/edit')->assertStatus($status);
})->with([['leah', 200], ['sam', 200], ['hannah', 403], ['grace', 403], ['kofi', 403]]);

it('validates the form next to each field', function () {
    Livewire::actingAs(persona('leah'))
        ->test(Form::class)
        ->set('form.email', 'kofi.mensah@freelance.test')
        ->call('save')
        ->assertHasErrors(['form.first_name', 'form.last_name', 'form.job_title', 'form.email']);
});

it('refuses duplicate skills', function () {
    Livewire::actingAs(persona('leah'))
        ->test(Form::class)
        ->set('form.first_name', 'A')->set('form.last_name', 'B')->set('form.email', 'ab@lumenco.test')->set('form.job_title', 'Producer')
        ->set('skills', [['skill_id' => 1, 'level' => 3], ['skill_id' => 1, 'level' => 4]])
        ->call('save')
        ->assertHasErrors('skills.1.skill_id');
});

it('stops someone being their own line manager', function () {
    $person = Person::query()->findOrFail(10);

    expect(fn () => app(SavePerson::class)->handle(persona('leah'), ['line_manager_id' => 10] + Livewire::actingAs(persona('leah'))->test(Form::class, ['person' => $person])->get('form'), $person))
        ->toThrow(ValidationException::class);
});

it('updates a person and logs it, keeping restricted fields for HR', function () {
    $jamie = Person::query()->findOrFail(10);

    Livewire::actingAs(persona('leah'))
        ->test(Form::class, ['person' => $jamie])
        ->assertSet('form.first_name', 'Jamie')
        ->set('form.job_title', 'Senior Graphic Designer')
        ->set('restricted.dietary_requirements', 'Vegan')
        ->call('save')
        ->assertHasNoErrors();

    expect($jamie->fresh()->job_title)->toBe('Senior Graphic Designer')
        ->and($jamie->fresh()->restricted->dietary_requirements)->toBe('Vegan');
    $this->assertDatabaseHas('activity_log', ['event' => 'person-updated', 'causer_id' => persona('leah')->id]);
});

it('shows new people everywhere the workspace reads people', function () {
    Livewire::actingAs(persona('leah'))
        ->test(Form::class)
        ->set('form.first_name', 'Nia')->set('form.last_name', 'Okon')->set('form.email', 'nia@lumenco.test')->set('form.job_title', 'Producer')
        ->call('save');

    $this->actingAs(persona('hannah'))->get('/people?search=Okon')->assertSee('Nia Okon');
});
