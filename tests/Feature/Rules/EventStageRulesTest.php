<?php

use App\Actions\Events\ChangeEventStage;
use App\Actions\Events\RecordSignedContract;
use App\Enums\EventStage;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('needs a client, dates and an estimated budget before a proposal', function () {
    // EV-26127 is an enquiry with no budget yet.
    expect(app(ChangeEventStage::class)->blockers(17, EventStage::ProposalSent))->toBe(['Add an estimated budget.']);
});

it('takes a producer’s event from enquiry to confirmed, logging every move (phase 2)', function () {
    $change = app(ChangeEventStage::class);
    $chloe = User::factory()->withRole(Role::Producer)->create(['person_id' => 6]);

    $change->handle($chloe, 16, EventStage::ProposalSent, 'Proposal sent to the client');

    expect(fn () => $change->handle($chloe, 16, EventStage::Confirmed))->toThrow(ValidationException::class, 'Upload the signed contract.');

    app(RecordSignedContract::class)->handle($chloe, 16);
    $change->handle($chloe, 16, EventStage::Confirmed);

    expect($this->workspace()->event(16)['stage'])->toBe('confirmed')
        ->and(collect($this->workspace()->event(16)['stage_history'])->pluck('to')->all())->toBe(['proposal-sent', 'confirmed']);
    expect(Activity::query()->where('causer_id', $chloe->id)->where('event', 'stage-changed')->count())->toBe(2)
        ->and(Activity::query()->where('causer_id', $chloe->id)->where('event', 'contract-signed')->count())->toBe(1);
});

it('refuses to skip stages', function () {
    app(ChangeEventStage::class)->handle(persona('hannah'), 14, EventStage::Live);
})->throws(ValidationException::class);

it('only lets the producer or the operations director move an event', function () {
    app(ChangeEventStage::class)->handle(persona('marcus'), 14, EventStage::Confirmed);
})->throws(AuthorizationException::class);

it('blocks reconciliation while open items remain, and lists them (rule 13)', function () {
    $blockers = app(ChangeEventStage::class)->blockers(5, EventStage::Reconciled);

    expect($blockers)->toBe([
        'PO-26-0050 is still open (Sent)',
        'PO-26-0051 is still open (Sent)',
        'Invoice NLV-3388 from Northlight Video is not approved yet (Matched)',
        '9 timesheet entries are not approved',
    ]);
});

it('lets finance reconcile but nothing else', function () {
    $grace = persona('grace');

    expect($grace->can('event.change-stage', [$this->workspace()->event(4), EventStage::Reconciled]))->toBeTrue()
        ->and($grace->can('event.change-stage', [$this->workspace()->event(14), EventStage::Confirmed]))->toBeFalse();
});
