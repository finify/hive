<?php

use App\Actions\Calendar\CalendarFeeds;
use App\Livewire\Settings\Notifications;
use App\Services\Calendar\IcsCalendar;
use Livewire\Livewire;

it('serves a private feed of someone’s bookings, holds, leave and travel', function () {
    $token = app(CalendarFeeds::class)->issue(persona('kofi'));

    $ics = $this->get(route('calendar.personal', $token))
        ->assertOk()
        ->assertHeader('content-type', 'text/calendar; charset=utf-8')
        ->getContent();

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain("SUMMARY:EV-26114 Northwind Systems Partner Summit 2026\r\n")
        ->toContain('STATUS:TENTATIVE')
        ->toContain('SUMMARY:EV-26117 hotel: Hotel Miradouro\, Baixa')
        ->toContain('DTSTART:20261108T071500Z')
        ->and(collect(explode("\r\n", $ics))->every(fn ($line) => strlen($line) <= 75))->toBeTrue();
});

it('keeps only a hash of the token, and a new one retires the old address', function () {
    $feeds = app(CalendarFeeds::class);
    $old = $feeds->issue(persona('jamie'));

    expect(persona('jamie')->refresh()->calendar_token_hash)->toBe(hash('sha256', $old));

    $new = $feeds->issue(persona('jamie'));
    $this->get(route('calendar.personal', $old))->assertNotFound();
    $this->get(route('calendar.personal', $new))->assertOk();

    $feeds->revoke(persona('jamie'));
    $this->get(route('calendar.personal', $new))->assertNotFound();
});

it('serves an event’s phases only to people who can see the event', function () {
    $kofi = app(CalendarFeeds::class)->issue(persona('kofi'));
    $priya = app(CalendarFeeds::class)->issue(persona('priya'));

    $this->get(route('calendar.event', [$priya, 'EV-26117']))->assertOk()->assertSee('SUMMARY:EV-26117 Travel: Halcyon Health European Congress', false);
    $this->get(route('calendar.event', [$kofi, 'EV-26126']))->assertNotFound();
});

it('escapes and folds lines as the standard asks', function () {
    expect(IcsCalendar::escape("A, b; c\\d\nNext"))->toBe('A\, b\; c\\\\d\nNext')
        ->and(IcsCalendar::fold(str_repeat('é', 50)))->toContain("\r\n ");
});

it('shows the address once, from the settings page', function () {
    Livewire::actingAs(persona('jamie'))->test(Notifications::class)
        ->assertSee('Turn on my calendar feed')
        ->call('issueFeed')
        ->assertSee('/calendar/')
        ->assertSee('copy it now');

    Livewire::actingAs(persona('jamie'))->test(Notifications::class)->assertDontSee('/calendar/')->assertSee('Get a new address');
});
