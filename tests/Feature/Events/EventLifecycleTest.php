<?php

use App\Actions\Events\ApplyTaskTemplate;
use App\Actions\Events\SaveEvent;
use App\Actions\Events\SaveTask;
use App\Enums\EventStage;
use App\Enums\EventType;
use App\Enums\TaskStatus;
use App\Http\Controllers\DocumentDownloadController;
use App\Livewire\Events\Form;
use App\Livewire\Events\Show;
use App\Models\Document;
use App\Models\Event;
use App\Models\Task;
use App\Models\TaskTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Storage::fake('local'));

it('lets a producer take a new enquiry to Confirmed, with the rules enforced and every change logged (phase 2 done)', function () {
    $hannah = persona('hannah');

    // 1. Add the enquiry.
    Livewire::actingAs($hannah)->test(Form::class)
        ->set('form.name', 'Juniper Bank Leadership Offsite')
        ->set('form.event_type', 'executive-summit')
        ->call('addMember')
        ->set('team.1', ['person_id' => 10, 'role' => 'Coordinator'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('events.show', 'EV-26128'));

    $event = Event::query()->where('code', 'EV-26128')->firstOrFail();
    expect($event->stage)->toBe(EventStage::Enquiry)->and($event->producer_id)->toBe(4);

    // 2. The proposal is blocked until there is a client, dates and a budget.
    $page = Livewire::actingAs($hannah)->test(Show::class, ['code' => 'EV-26128'])
        ->call('prepareStage', 'proposal-sent')
        ->assertSet('stageBlockers', ['Add the client.', 'Add outline dates.', 'Add an estimated budget.']);

    Livewire::actingAs($hannah)->test(Form::class, ['code' => 'EV-26128'])
        ->set('form.client_id', 1)
        ->set('form.start_date', '2027-03-10')
        ->set('form.end_date', '2027-03-11')
        ->set('form.client_fee', '64000')
        ->call('save')
        ->assertHasNoErrors();

    $page = Livewire::actingAs($hannah)->test(Show::class, ['code' => 'EV-26128'])
        ->call('prepareStage', 'proposal-sent')
        ->assertSet('stageBlockers', [])
        ->set('stageNote', 'Sent v1 with two venue options')
        ->call('changeStage');

    // 3. Confirmation needs the signed contract.
    $page->call('prepareStage', 'confirmed')->assertSet('stageBlockers', ['Upload the signed contract.']);

    $page->call('startContractUpload')
        ->assertSet('tab', 'files')
        ->set('upload', UploadedFile::fake()->create('Juniper signed.pdf', 300, 'application/pdf'))
        ->call('uploadFile')
        ->assertHasNoErrors();

    $page->call('prepareStage', 'confirmed')->assertSet('stageBlockers', [])->call('changeStage');

    $event->refresh()->load('tasks');
    $template = TaskTemplate::query()->where('event_type', 'executive-summit')->firstOrFail()->items()->count();

    expect($event->stage)->toBe(EventStage::Confirmed)
        ->and($event->stageChanges()->pluck('to_stage')->map->value->all())->toBe(['enquiry', 'proposal-sent', 'confirmed'])
        ->and($event->tasks)->toHaveCount($template)
        ->and($event->tasks->firstWhere('title', 'Contract countersigned')->assignee_id)->toBe(4)
        ->and($event->tasks->firstWhere('title', 'Invitations sent')->assignee_id)->toBe(10)
        ->and($event->tasks->firstWhere('title', 'Invitations sent')->due_date->toDateString())->toBe('2026-12-25');

    expect(Activity::query()->where('properties->ref', 'EV-26128')->pluck('event')->all())
        ->toBe(['event-created', 'event-updated', 'stage-changed', 'contract-signed', 'stage-changed']);

    $update = Activity::query()->where('properties->ref', 'EV-26128')->where('event', 'event-updated')->firstOrFail();
    expect($update->properties['old'])->toMatchArray(['client_id' => null, 'start_date' => null, 'client_fee' => null])
        ->and($update->properties['attributes'])->toMatchArray(['client_id' => 1, 'start_date' => '2027-03-10', 'client_fee' => '£64,000.00']);
});

it('numbers new events by year, counting deleted ones', function () {
    $save = app(SaveEvent::class);
    expect($save->nextCode())->toBe('EV-26128');

    Event::factory()->create(['code' => 'EV-26140'])->delete();

    expect($save->nextCode())->toBe('EV-26141');
});

it('validates the event form', function () {
    Livewire::actingAs(persona('hannah'))->test(Form::class)
        ->set('form.name', '')
        ->set('form.start_date', '2027-03-10')
        ->set('form.end_date', '2027-03-01')
        ->set('form.client_fee', '£12k')
        ->call('addMember')
        ->set('team.1', ['person_id' => 4, 'role' => 'Coordinator'])
        ->call('save')
        ->assertHasErrors(['form.name', 'form.end_date', 'form.client_fee', 'team.0.person_id', 'team.1.person_id']);

    expect(Event::query()->count())->toBe(18);
});

it('never changes the stage through the details form', function () {
    app(SaveEvent::class)->handle(persona('hannah'), ['name' => 'Renamed', 'event_type' => 'conference', 'stage' => 'confirmed'], Event::query()->findOrFail(14));

    expect(Event::query()->findOrFail(14)->stage)->toBe(EventStage::ProposalSent);
});

it('limits who may add and edit events', function () {
    $this->actingAs(persona('kofi'))->get(route('events.create'))->assertForbidden();
    $this->actingAs(persona('grace'))->get(route('events.create'))->assertForbidden();
    $this->actingAs(persona('hannah'))->get(route('events.create'))->assertOk();

    // Marcus produces his own events, not Hannah's.
    $this->actingAs(persona('marcus'))->get(route('events.edit', 'EV-26123'))->assertForbidden();
    $this->actingAs(persona('hannah'))->get(route('events.edit', 'EV-26123'))->assertOk();
    $this->actingAs(persona('priya'))->get(route('events.edit', 'EV-26123'))->assertOk();

    expect(fn () => app(SaveEvent::class)->handle(persona('marcus'), ['name' => 'Taken over', 'event_type' => 'conference'], Event::query()->findOrFail(14)))
        ->toThrow(AuthorizationException::class);
});

it('adds the standard list only to an event without tasks', function () {
    $apply = app(ApplyTaskTemplate::class);

    expect(fn () => $apply->handle(persona('hannah'), Event::query()->findOrFail(14)))->toThrow(ValidationException::class);

    // EV-26127 is Chloe's; the operations director may add the list.
    expect($apply->handle(persona('priya'), Event::query()->findOrFail(17)))->toBe(TaskTemplate::query()->where('event_type', 'brand-activation')->firstOrFail()->items()->count());
    $this->assertDatabaseHas('activity_log', ['event' => 'tasks-added', 'causer_id' => persona('priya')->id]);
});

it('has a standard list for every event type, with about thirty items for conferences', function () {
    expect(TaskTemplate::query()->count())->toBe(count(EventType::cases()))
        ->and(TaskTemplate::query()->where('event_type', 'conference')->firstOrFail()->items()->count())->toBe(30);
});

it('adds tasks and moves them on, logging before and after', function () {
    $hannah = persona('hannah');

    Livewire::actingAs($hannah)->test(Show::class, ['code' => 'EV-26123'])
        ->set('tab', 'tasks')
        ->set('newTask', ['title' => 'Book the photographer', 'assignee_id' => '10', 'due_date' => '2026-11-20'])
        ->call('addTask')
        ->assertHasNoErrors()
        ->assertSee('Book the photographer');

    $task = Task::query()->where('title', 'Book the photographer')->firstOrFail();

    Livewire::actingAs($hannah)->test(Show::class, ['code' => 'EV-26123'])->call('cycleTask', $task->id);

    expect($task->refresh()->status)->toBe(TaskStatus::InProgress);
    $log = Activity::query()->where('event', 'task-updated')->latest('id')->firstOrFail();
    expect($log->properties['old'])->toBe(['status' => 'to-do'])->and($log->properties['attributes'])->toBe(['status' => 'in-progress']);
});

it('lets the assignee tick off their own task, and nobody else outside the event', function () {
    $task = Task::query()->create(['event_id' => 14, 'title' => 'Collect speaker bios', 'assignee_id' => 10, 'status' => 'to-do']);

    app(SaveTask::class)->setStatus(persona('jamie'), $task, TaskStatus::Done);
    expect($task->refresh()->status)->toBe(TaskStatus::Done);

    expect(fn () => app(SaveTask::class)->setStatus(persona('marcus'), $task, TaskStatus::ToDo))->toThrow(AuthorizationException::class);
});

it('stores event files privately and opens them only for people who can see the event', function () {
    Livewire::actingAs(persona('hannah'))->test(Show::class, ['code' => 'EV-26117'])
        ->set('tab', 'files')
        ->set('fileType', 'floor-plan')
        ->set('upload', UploadedFile::fake()->create('Lisbon floor plan v3.pdf', 500, 'application/pdf'))
        ->call('uploadFile')
        ->assertHasNoErrors()
        ->assertSee('Lisbon floor plan v3.pdf');

    $document = Document::query()->where('original_name', 'Lisbon floor plan v3.pdf')->firstOrFail();
    Storage::disk('local')->assertExists($document->file_path);
    expect($document->file_path)->toStartWith('documents/events/9/');

    $link = DocumentDownloadController::link($document);
    $this->actingAs(persona('priya'))->get($link)->assertOk();
    // Kofi is booked on the Lisbon event; Jamie is not on it.
    $this->actingAs(persona('kofi'))->get($link)->assertOk();
    $this->actingAs(persona('jamie'))->get($link)->assertForbidden();
});

it('rejects files that are not documents or images', function () {
    Livewire::actingAs(persona('hannah'))->test(Show::class, ['code' => 'EV-26117'])
        ->set('tab', 'files')
        ->set('upload', UploadedFile::fake()->create('script.exe', 10))
        ->call('uploadFile')
        ->assertHasErrors(['upload']);

    expect(Document::query()->where('original_name', 'script.exe')->exists())->toBeFalse();
});
