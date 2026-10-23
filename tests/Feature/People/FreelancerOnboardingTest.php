<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Livewire\People\Form;
use App\Livewire\People\Show;
use App\Models\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Phase 1 is done when HR can create a freelancer with skills, a rate and an
 * insurance document, and a producer viewing the same person sees no rate
 * and no restricted fields.
 */
it('lets HR add a freelancer with skills, a rate and insurance, hidden from producers (phase 1)', function () {
    Storage::fake('local');

    Livewire::actingAs(persona('leah'))
        ->test(Form::class)
        ->set('form.employment_type', 'freelancer')
        ->set('form.first_name', 'Priti')
        ->set('form.last_name', 'Varma')
        ->set('form.email', 'priti.varma@freelance.test')
        ->set('form.job_title', 'AV Technician')
        ->set('form.department_id', 3)
        ->set('rate.amount', '410')
        ->call('addSkill')
        ->set('skills.0.skill_id', 1)
        ->set('skills.0.level', 4)
        ->set('restricted.emergency_contact', 'Arun Varma, 07700 900123')
        ->set('restricted.passport_number', 'X1234567')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $priti = Person::query()->where('email', 'priti.varma@freelance.test')->with(['dayRates', 'skills', 'restricted'])->firstOrFail();
    expect($priti->dayRates)->toHaveCount(1)
        ->and($priti->dayRates->first()->amount_minor)->toBe(41_000)
        ->and($priti->skills->first()->name)->toBe('Disguise media server')
        ->and($priti->restricted->passport_number)->toBe('X1234567');

    Livewire::actingAs(persona('leah'))
        ->test(Show::class, ['person' => $priti->id])
        ->set('newDocument.title', 'Public liability insurance £5m')
        ->set('newDocument.expires_at', '2027-10-31')
        ->set('upload', UploadedFile::fake()->create('insurance.pdf', 120, 'application/pdf'))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $document = $priti->documents()->firstOrFail();
    expect($document->mandatory)->toBeTrue();
    Storage::disk('local')->assertExists($document->file_path);

    // HR sees everything.
    $this->actingAs(persona('leah'))->get("/people/{$priti->id}?tab=rates")->assertSee('£410.00');
    $this->actingAs(persona('leah'))->get("/people/{$priti->id}?tab=restricted")->assertSee('Arun Varma');

    // The producer sees neither the rate nor the restricted fields, nor the file.
    $hannah = $this->actingAs(persona('hannah'));
    $hannah->get("/people/{$priti->id}")->assertOk()->assertDontSee('Rates')->assertDontSee('Restricted');
    $hannah->get("/people/{$priti->id}?tab=rates")->assertDontSee('£410');
    $hannah->get("/people/{$priti->id}?tab=restricted")->assertDontSee('Arun Varma')->assertDontSee('X1234567');
    $hannah->get("/people/{$priti->id}?tab=documents")->assertSee('Public liability insurance')->assertDontSee('Open file');
    $hannah->get(DocumentDownloadController::link($document))->assertForbidden();
});

it('encrypts emergency contacts, passports and confidential notes at rest', function () {
    $raw = DB::table('person_restricted')->where('person_id', 1)->first();

    expect($raw->passport_number)->not->toBe('553018274')
        ->and(Person::query()->find(1)->restricted->passport_number)->toBe('553018274')
        ->and($raw->emergency_contact)->not->toContain('Raman');
});

it('serves files only through signed links to permitted people', function () {
    Storage::fake('local');
    $kofi = Person::query()->findOrFail(26);
    $document = $kofi->documents()->create(['type' => 'qualification', 'title' => 'IPAF', 'file_path' => 'documents/people/26/ipaf.pdf', 'original_name' => 'ipaf.pdf']);
    Storage::disk('local')->put('documents/people/26/ipaf.pdf', '%PDF-1.4');

    $this->actingAs(persona('kofi'))->get(DocumentDownloadController::link($document))->assertOk();
    $this->actingAs(persona('leah'))->get(DocumentDownloadController::link($document))->assertOk();
    $this->actingAs(persona('grace'))->get(DocumentDownloadController::link($document))->assertForbidden();
    $this->actingAs(persona('leah'))->get(route('documents.download', $document))->assertForbidden();

    $this->assertDatabaseHas('activity_log', ['event' => 'document-opened', 'subject_id' => $document->id]);
});

it('rejects files that are not documents or are too large', function (UploadedFile $file, string $message) {
    Storage::fake('local');

    Livewire::actingAs(persona('leah'))
        ->test(Show::class, ['person' => 26])
        ->set('tab', 'documents')
        ->set('newDocument.title', 'Insurance')
        ->set('upload', $file)
        ->call('uploadDocument')
        ->assertHasErrors('upload')
        ->assertSee($message);
})->with([
    'an executable' => [fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), 'Upload a PDF, JPG, PNG or Word document.'],
    'over 10 MB' => [fn () => UploadedFile::fake()->create('huge.pdf', 11_000, 'application/pdf'), 'Files can be up to 10 MB.'],
]);

it('lets freelancers upload their own documents but not other people’s', function () {
    Storage::fake('local');
    $file = fn () => UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf');

    Livewire::actingAs(persona('kofi'))->test(Show::class, ['person' => 26])
        ->set('newDocument.title', 'First aid at work')->set('newDocument.type', 'qualification')->set('upload', $file())
        ->call('uploadDocument')->assertHasNoErrors();

    expect(persona('kofi')->can('uploadDocument', Person::query()->find(27)))->toBeFalse();
});
