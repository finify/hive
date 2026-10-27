<?php

namespace App\Actions\Calendar;

use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Enums\EventStage;
use App\Enums\TravelKind;
use App\Enums\TravelStatus;
use App\Models\TravelBooking;
use App\Models\User;
use App\Services\Calendar\IcsCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Private calendar feeds (section 5.13). A user gets one secret URL with
 * their bookings, leave and travel, which also opens the feeds of events
 * they can see. Only a hash of the token is stored; issuing a new one
 * retires the old URL.
 */
class CalendarFeeds
{
    public function __construct(private readonly Workspace $workspace) {}

    public function issue(User $user): string
    {
        $token = Str::random(40);
        $user->forceFill(['calendar_token_hash' => hash('sha256', $token)])->save();

        activity('identity')->causedBy($user)->performedOn($user)
            ->withProperties(['ref' => 'U-'.$user->id])->event('calendar-feed-issued')->log('Issued a new calendar feed address');

        return $token;
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['calendar_token_hash' => null])->save();

        activity('identity')->causedBy($user)->performedOn($user)
            ->withProperties(['ref' => 'U-'.$user->id])->event('calendar-feed-revoked')->log('Turned off their calendar feed');
    }

    public function userFor(string $token): ?User
    {
        return strlen($token) === 40 ? User::query()->where('calendar_token_hash', hash('sha256', $token))->where('is_active', true)->first() : null;
    }

    public function personal(User $user): string
    {
        $cal = new IcsCalendar('Hive: '.$user->name);
        $personId = $user->person_id;
        $from = $this->workspace->today()->subMonths(3)->toDateString();

        if ($personId === null) {
            return $cal->render();
        }

        foreach ($this->workspace->assignments()->where('person_id', $personId) as $a) {
            if ($a['end_date'] < $from || ! in_array($a['status'], ['confirmed', 'first-hold', 'second-hold'], true)) {
                continue;
            }

            $event = $this->workspace->event($a['event_id']);
            $call = $this->workspace->crewCalls()->get($a['crew_call_id']);
            $hold = $a['status'] !== 'confirmed';

            $cal->allDay('booking-'.$a['id'], $a['start_date'], $a['end_date'],
                ($hold ? 'Hold: ' : '').($event['code'] ?? '').' '.($event['name'] ?? ''),
                trim(($call['role_name'] ?? '').($call ? ", call {$call['call_time']}" : '').($hold ? '. Not confirmed yet.' : '')),
                $call['location'] ?? $event['city'] ?? null, $hold);
        }

        foreach ($this->workspace->leaveRequests()->where('person_id', $personId) as $l) {
            if ($l['end_date'] >= $from && in_array($l['status'], ['approved', 'pending'], true)) {
                $label = $this->workspace->leaveType($l['leave_type'])['name'] ?? 'Leave';
                $cal->allDay('leave-'.$l['id'], $l['start_date'], $l['end_date'], ($l['status'] === 'pending' ? 'Requested: ' : '').$label, tentative: $l['status'] === 'pending');
            }
        }

        foreach (TravelBooking::query()->where('person_id', $personId)->where('status', TravelStatus::Booked)->get() as $t) {
            $code = $this->workspace->event($t->event_id)['code'] ?? '';

            if ($t->kind === TravelKind::Accommodation && $t->check_in && $t->check_out) {
                $cal->allDay('travel-'.$t->id, $t->check_in->toDateString(), $t->check_out->subDay()->toDateString(), "{$code} hotel: {$t->provider}", $t->reference ? "Ref {$t->reference}" : null, $t->to_place);
            } elseif ($t->depart_at) {
                // Travel times are local to the place of departure; the feed treats them as UK time.
                $start = CarbonImmutable::parse($t->depart_at->format('Y-m-d H:i'), 'Europe/London');
                $end = $t->arrive_at ? CarbonImmutable::parse($t->arrive_at->format('Y-m-d H:i'), 'Europe/London') : null;
                $cal->timed('travel-'.$t->id, $start, $end && $end->gt($start) ? $end : null, "{$code}: ".$t->summary(), $t->reference ? "Ref {$t->reference}" : null, $t->from_place);
            }
        }

        return $cal->render();
    }

    public function event(User $user, string $code): ?string
    {
        $event = $this->workspace->eventByCode($code);

        if ($event === null || ! (new Viewer($user, $this->workspace))->canSeeEvent($event)) {
            return null;
        }

        $cal = new IcsCalendar("Hive: {$event['code']} {$event['name']}");
        $tentative = in_array($event['stage'], ['enquiry', 'proposal-sent'], true);

        foreach ($event['phases'] as $phase) {
            $cal->allDay("event-{$event['id']}-{$phase['id']}", $phase['start_date'], $phase['end_date'],
                "{$event['code']} ".ucfirst($phase['type']).": {$event['name']}", EventStage::from($event['stage'])->label(), $event['city'], $tentative);
        }

        return $cal->render();
    }
}
