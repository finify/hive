<?php

use App\Actions\Integrations\ImportCsv;
use App\Livewire\Admin\Import;
use App\Models\Client;
use App\Models\Person;
use App\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function csvOf(array $rows): string
{
    return implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => str_contains($c, ',') ? '"'.$c.'"' : $c, $r)), $rows));
}

it('previews every row with the add form’s rules, saving nothing', function () {
    $import = app(ImportCsv::class);
    $rows = $import->parse('people', csvOf([
        ['first_name', 'last_name', 'email', 'employment_type', 'job_title', 'location_city', 'location_country', 'start_date', 'department', 'line_manager_email'],
        ['Ada', 'Mensah', 'ada@lumenco.test', 'employee', 'Producer', 'London', 'United Kingdom', '2027-01-04', 'Production', 'priya@lumenco.test'],
        ['Ben', 'Okafor', 'ada@lumenco.test', 'employee', 'Producer', 'London', 'United Kingdom', '2027-01-04', 'Production', ''],
        ['Cleo', '', 'cleo@lumenco.test', 'contractor', 'Designer', 'Leeds', 'United Kingdom', 'soon', 'Nowhere', ''],
    ]));
    $before = Person::query()->count();

    $checked = $import->preview(persona('leah'), 'people', $rows);

    expect($checked[0]['errors'])->toBe([])
        ->and(implode(' ', $checked[1]['errors']))->toContain('email has already been taken')
        ->and($checked[2]['errors'])->toBe(['There is no department called Nowhere.'])
        ->and(Person::query()->count())->toBe($before);
});

it('imports only when every row passes, all or nothing', function () {
    $import = app(ImportCsv::class);
    $good = $import->parse('venues', csvOf([
        ['name', 'address', 'city', 'country', 'capacity'],
        ['Example Hall', '1 Example Road', 'Manchester', 'United Kingdom', '800'],
        ['Second Space', '2 Example Road', 'Leeds', 'United Kingdom', ''],
    ]));

    expect($import->import(persona('hannah'), 'venues', $good))->toBe(2)
        ->and(Venue::query()->where('name', 'Second Space')->exists())->toBeTrue();

    $bad = $import->parse('venues', csvOf([['name', 'address', 'city', 'country'], ['Third', '3 Road', 'York', 'United Kingdom'], ['Example Hall', 'x', 'y', 'z']]));
    expect(fn () => $import->import(persona('hannah'), 'venues', $bad))->toThrow(ValidationException::class, '1 rows have problems');
    expect(Venue::query()->where('name', 'Third')->exists())->toBeFalse();
});

it('rejects unknown columns, empty files and people without permission', function () {
    $import = app(ImportCsv::class);

    expect(fn () => $import->parse('clients', "name,favourite_colour\nA,blue"))->toThrow(ValidationException::class, 'Unknown columns: favourite_colour');
    expect(fn () => $import->parse('clients', ''))->toThrow(ValidationException::class, 'The file is empty');
    expect(fn () => $import->preview(persona('hannah'), 'people', []))->toThrow(AuthorizationException::class);
});

it('runs from the Import page with a template to start from', function () {
    expect($this->actingAs(persona('hannah'))->get(route('import.template', 'clients'))->assertOk()->streamedContent())->toContain('payment_terms_days');
    $this->actingAs(persona('jamie'))->get(route('import'))->assertForbidden();

    $file = UploadedFile::fake()->createWithContent('clients.csv', csvOf([
        ['name', 'sector', 'payment_terms_days', 'billing_address'],
        ['Example Group plc', 'Financial services', '30', '1 Example Street, London'],
    ]));

    Livewire::actingAs(persona('hannah'))->test(Import::class)
        ->assertSet('type', 'clients')
        ->set('file', $file)
        ->call('check')
        ->assertSee('All 1 rows are ready')
        ->call('import')
        ->assertHasNoErrors();

    expect(Client::query()->where('name', 'Example Group plc')->exists())->toBeTrue();
});
