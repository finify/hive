<?php

use App\Actions\Travel\SaveTravelBooking;
use App\Actions\Travel\TravelManifest;
use App\Demo\Workspace;
use App\Livewire\Events\Show;
use App\Livewire\Events\Travel;
use App\Models\CostEntry;
use App\Models\PersonRestricted;
use App\Models\TravelBooking;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function travelActual(int $eventId, string $category): int
{
    return app(Workspace::class)->budgetLines($eventId)->where('category', $category)->sum(fn ($l) => $l['actual']->minor);
}

function flight(array $overrides = []): array
{
    return $overrides + [
        'person_id' => 16, 'kind' => 'outbound', 'mode' => 'rail', 'from_place' => 'London St Pancras', 'to_place' => 'Paris Nord',
        'depart_at' => '2026-11-07 09:01', 'arrive_at' => '2026-11-07 12:17', 'provider' => 'Eurostar', 'reference' => 'ES123', 'cost' => '210.00', 'currency' => 'GBP',
    ];
}

it('posts the cost of travel to the event’s Travel line, the difference on a change, and reverses it on cancelling', function () {
    $save = app(SaveTravelBooking::class);
    $before = travelActual(9, 'travel');

    $booking = $save->handle(persona('hannah'), 9, flight());
    expect(travelActual(9, 'travel') - $before)->toBe(21000);

    $save->handle(persona('hannah'), 9, flight(['cost' => '185.50']), $booking->id);
    expect(travelActual(9, 'travel') - $before)->toBe(18550)
        ->and(CostEntry::query()->where('source_type', 'travel')->where('source_id', $booking->id)->pluck('amount_minor')->all())->toBe([21000, -2450]);

    $save->cancel(persona('hannah'), $booking->id);
    expect(travelActual(9, 'travel'))->toBe($before)
        ->and($booking->refresh()->status->value)->toBe('cancelled')
        ->and(Activity::query()->where('event', 'travel-cancelled')->exists())->toBeTrue();
});

it('puts hotels on the Accommodation line, in their own currency', function () {
    $before = travelActual(9, 'accommodation');

    app(SaveTravelBooking::class)->handle(persona('hannah'), 9, [
        'person_id' => 16, 'kind' => 'accommodation', 'mode' => 'hotel', 'provider' => 'Hotel Avenida', 'check_in' => '2026-11-13', 'check_out' => '2026-11-14',
        'cost' => '150', 'currency' => 'EUR',
    ]);

    $entry = CostEntry::query()->where('source_type', 'travel')->latest('id')->firstOrFail();
    expect($entry->currency)->toBe('EUR')
        ->and($entry->amount_minor)->toBe(15000)
        ->and(travelActual(9, 'accommodation') - $before)->toBe($entry->amount_gbp_minor);
});

it('switches currency by reversing the old amount and posting the new one', function () {
    $save = app(SaveTravelBooking::class);
    $booking = $save->handle(persona('hannah'), 9, flight());
    $save->handle(persona('hannah'), 9, flight(['cost' => '240', 'currency' => 'EUR']), $booking->id);

    $entries = CostEntry::query()->where('source_id', $booking->id)->where('source_type', 'travel')->get(['amount_minor', 'currency'])->toArray();
    expect($entries)->toBe([
        ['amount_minor' => 21000, 'currency' => 'GBP'],
        ['amount_minor' => -21000, 'currency' => 'GBP'],
        ['amount_minor' => 24000, 'currency' => 'EUR'],
    ]);
});

it('checks the traveller is on the event and the details fit the kind of booking', function () {
    $save = app(SaveTravelBooking::class);

    expect(fn () => $save->handle(persona('hannah'), 9, flight(['person_id' => 10])))->toThrow(ValidationException::class, 'Only people booked on or running this event');
    try {
        $save->handle(persona('hannah'), 9, ['person_id' => 16, 'kind' => 'accommodation', 'mode' => 'hotel', 'check_in' => '2026-11-13', 'check_out' => '2026-11-12', 'currency' => 'GBP']);
        $this->fail('Expected validation errors');
    } catch (ValidationException $e) {
        expect($e->errors())->toMatchArray(['provider' => ['Name the hotel.'], 'check_out' => ['Check-out is after check-in.']]);
    }
    expect(fn () => $save->handle(persona('hannah'), 9, flight(['mode' => 'hotel'])))->toThrow(ValidationException::class, 'Choose how they travel');
});

it('lets only people who run the event’s crew book travel', function () {
    app(SaveTravelBooking::class)->handle(persona('marcus'), 9, flight());
})->throws(AuthorizationException::class);

it('exports a manifest with passport details only for people allowed them, and logs it', function () {
    PersonRestricted::query()->updateOrCreate(['person_id' => 16], ['passport_number' => '553018274', 'nationality' => 'British']);

    $producer = app(TravelManifest::class)->build(persona('hannah'), 'EV-26117');
    $flat = json_encode($producer['rows']);

    expect($producer['rows'][0])->not->toContain('Passport number')
        ->and($producer['rows'][0])->toContain('Cost')
        ->and($flat)->not->toContain('553018274')
        ->and($flat)->toContain('Hotel Miradouro, Baixa')
        ->and(count($producer['rows']))->toBe(11);

    $admin = app(TravelManifest::class)->build(persona('sam'), 'EV-26117');
    expect($admin['rows'][0])->toContain('Passport number')
        ->and(json_encode($admin['rows']))->toContain('553018274')
        ->and(Activity::query()->where('event', 'travel-manifest-exported')->latest('id')->value('description'))->toContain('with passport details');

    $this->actingAs(persona('hannah'))->get(route('travel.manifest', 'EV-26117'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $this->actingAs(persona('kofi'))->get(route('travel.manifest', 'EV-26117'))->assertForbidden();
});

it('shows the travel tab with costs only to people who may see the budget', function () {
    Livewire::actingAs(persona('hannah'))->test(Show::class, ['code' => 'EV-26117'])->set('tab', 'travel')->assertSee('Travel');

    Livewire::actingAs(persona('hannah'))->test(Travel::class, ['eventId' => 9])
        ->assertSee('Freya Collins')
        ->assertSee('Hotel Miradouro, Baixa')
        ->assertSee('£186.00')
        ->call('startBooking', 16)
        ->set('travelForm.from_place', 'London')->set('travelForm.to_place', 'Lisbon')->set('travelForm.depart_at', '2026-11-08T06:00')
        ->set('travelForm.mode', 'flight')->set('travelForm.cost', '99')
        ->call('save')
        ->assertHasNoErrors();

    expect(TravelBooking::query()->where('cost_minor', 9900)->exists())->toBeTrue();

    Livewire::actingAs(persona('tomasz'))->test(Travel::class, ['eventId' => 9])
        ->assertSee('Freya Collins')
        ->assertDontSee('£186.00')
        ->assertDontSee('Add travel');
});
