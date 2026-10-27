<?php

use App\Actions\Hr\StartChecklist;
use App\Actions\Hr\TickChecklistItem;
use App\Enums\ChecklistKind;
use App\Livewire\People\Onboarding;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('seeds onboarding for the new starter and offboarding for the leaver', function () {
    $joel = Checklist::query()->where('person_id', 61)->sole();
    $liam = Checklist::query()->where('person_id', 21)->sole();

    expect($joel->kind)->toBe(ChecklistKind::Onboarding)
        ->and($joel->anchor_date->toDateString())->toBe('2026-11-02')
        ->and($joel->items()->where('title', 'Laptop issued')->sole()->due_date->toDateString())->toBe('2026-11-02')
        ->and($joel->items()->whereNotNull('completed_at')->count())->toBeGreaterThan(0)
        ->and($liam->kind)->toBe(ChecklistKind::Offboarding)
        ->and($liam->anchor_date->toDateString())->toBe('2026-11-27');
});

it('starts a checklist from the employment type’s template, once', function () {
    $start = app(StartChecklist::class);
    $person = Person::query()->findOrFail(10);

    expect(fn () => $start->handle(persona('leah'), $person, ChecklistKind::Offboarding))
        ->toThrow(ValidationException::class, 'Add Jamie’s leaving date first');

    $person->update(['end_date' => '2026-12-18']);
    $checklist = $start->handle(persona('leah'), $person, ChecklistKind::Offboarding);

    expect($checklist->items()->pluck('title')->all())->toContain('Exit interview', 'Accounts disabled')
        ->and($checklist->items()->where('title', 'Equipment returned')->sole()->due_date->toDateString())->toBe('2026-12-18');

    expect(fn () => $start->handle(persona('leah'), $person, ChecklistKind::Offboarding))
        ->toThrow(ValidationException::class, 'already has an open offboarding');
    expect(fn () => $start->handle(persona('hannah'), $person, ChecklistKind::Onboarding))
        ->toThrow(AuthorizationException::class);
});

it('lets each owner tick only their own items, and closes the checklist when all are done', function () {
    $liam = Checklist::query()->where('person_id', 21)->sole();
    $item = fn (string $title) => ChecklistItem::query()->where('checklist_id', $liam->id)->where('title', $title)->sole();
    $tick = app(TickChecklistItem::class);

    // Grace is Liam's line manager, and in finance.
    $tick->handle(persona('grace'), $item('Handover plan agreed')->id);
    $tick->handle(persona('grace'), $item('Final pay confirmed')->id);
    expect(fn () => $tick->handle(persona('grace'), $item('Exit interview')->id))->toThrow(AuthorizationException::class, 'This item is for HR');
    expect(fn () => $tick->handle(persona('jamie'), $item('Equipment returned')->id))->toThrow(AuthorizationException::class);

    foreach ($liam->items()->whereNull('completed_at')->get() as $open) {
        $tick->handle(persona('leah'), $open->id);
    }

    expect($liam->refresh()->completed_at)->not->toBeNull();

    $tick->handle(persona('leah'), $item('Exit interview')->id, false);
    expect($liam->refresh()->completed_at)->toBeNull();
});

it('shows HR every checklist and a line manager the ones with their items', function () {
    Livewire::actingAs(persona('leah'))->test(Onboarding::class)
        ->assertSee('Joel Mendes')
        ->assertSee('Liam Walsh')
        ->assertSee('Start a checklist');

    // Grace manages Liam, and owns finance items on both.
    Livewire::actingAs(persona('grace'))->test(Onboarding::class)
        ->assertSee('Liam Walsh')
        ->assertSee('Bank details received by payroll')
        ->assertDontSee('Start a checklist');

    // Tomasz approves leave but manages neither and owns no items.
    Livewire::actingAs(persona('tomasz'))->test(Onboarding::class)
        ->assertDontSee('Liam Walsh')
        ->assertDontSee('Joel Mendes')
        ->assertSee('No open checklists');

    $this->actingAs(persona('kofi'))->get(route('onboarding'))->assertForbidden();
});
