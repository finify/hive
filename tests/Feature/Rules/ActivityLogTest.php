<?php

use App\Actions\Events\ChangeEventStage;
use App\Actions\Finance\ApproveSupplierInvoice;
use App\Actions\People\DecideLeaveRequest;
use App\Actions\Resourcing\RespondToBookingRequest;
use App\Actions\Time\ApproveTimesheetEntry;
use App\Enums\EventStage;
use Spatie\Activitylog\Models\Activity;

it('writes who, what and before and after for every state change (rule 20)', function (Closure $act, string $log, string $event) {
    $act();

    $entry = Activity::query()->where('log_name', $log)->where('event', $event)->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->not->toBeNull()
        ->and($entry->properties->has('attributes'))->toBeTrue();
})->with([
    'stage change' => [fn () => app(ChangeEventStage::class)->handle(persona('hannah'), 14, EventStage::Lost, 'Client went elsewhere'), 'events', 'stage-changed'],
    'timesheet approval' => [fn () => app(ApproveTimesheetEntry::class)->handle(persona('hannah'), collect(test()->workspace()->timesheets()->first(fn ($s) => $s['person_id'] === 26 && $s['week_start'] === '2026-10-12')['entries'])->first()['id']), 'time', 'approved'],
    'invoice approval' => [fn () => app(ApproveSupplierInvoice::class)->handle(persona('grace'), test()->workspace()->supplierInvoices()->firstWhere('invoice_number', 'NLV-3388')['id']), 'finance', 'approved'],
    'booking reply' => [fn () => app(RespondToBookingRequest::class)->handle(persona('kofi'), test()->workspace()->assignments()->first(fn ($a) => $a['person_id'] === 26 && $a['event_id'] === 9)['id'], false), 'resourcing', 'declined'],
    'leave decision' => [fn () => app(DecideLeaveRequest::class)->handle(persona('leah'), 2, true), 'people', 'approved'],
]);
