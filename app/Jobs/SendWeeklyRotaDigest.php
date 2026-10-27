<?php

namespace App\Jobs;

use App\Data\Clash;
use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Enums\EventStage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Monday 08:00: tells each producer and head of department what needs
 * resourcing over the next four weeks. Producers hear about clashes and
 * unfilled crew calls on their events; heads of department about clashes
 * for people in their department and every unfilled crew call. Nothing is
 * sent to someone with nothing to do.
 */
class SendWeeklyRotaDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Not retried: a second run would email the first people again. Failures show on the Queue page. */
    public int $tries = 1;

    public function handle(Workspace $workspace): void
    {
        $workspace->forget();
        $horizon = $workspace->today()->addWeeks(4)->toDateString();
        $today = $workspace->today()->toDateString();

        $events = $workspace->events()->reject(fn ($e) => in_array($e['stage'], [EventStage::Cancelled->value, EventStage::Lost->value, EventStage::Closed->value], true));

        $openCalls = $workspace->crewCalls()
            ->filter(fn ($c) => $events->has($c['event_id']) && $c['end_date'] >= $today && $c['start_date'] <= $horizon)
            ->filter(fn ($c) => $workspace->assignments()->where('crew_call_id', $c['id'])
                ->filter(fn ($a) => in_array($a['status'], [AssignmentStatus::Confirmed->value, AssignmentStatus::FirstHold->value], true))->count() < $c['quantity']);

        $clashes = collect($workspace->clashes())->filter(fn (Clash $c) => $c->dates->start->toDateString() <= $horizon);

        $recipients = User::query()->active()->whereNotNull('person_id')->role(['producer', 'head-of-department'])->get();

        foreach ($recipients as $user) {
            $team = $user->hasRole('head-of-department')
                ? $workspace->people()->whereIn('department_id', $workspace->departments()->where('head_person_id', $user->person_id)->keys())->keys()->all()
                : null;
            $mine = fn (?int $eventId) => $eventId !== null && ($events->get($eventId)['producer_id'] ?? null) === $user->person_id;

            $calls = $openCalls->filter(fn ($c) => $team !== null || $mine($c['event_id']))->count();
            $clashCount = $clashes->filter(fn (Clash $c) => $team !== null ? in_array($c->personId, $team, true) : ($mine($c->first->eventId) || $mine($c->second->eventId)))->count();

            if ($calls === 0 && $clashCount === 0) {
                continue;
            }

            $workspace->notify($user->person_id, 'clash', 'Rota this week', sprintf(
                '%d %s and %d open crew %s in the next four weeks.',
                $clashCount, $clashCount === 1 ? 'clash' : 'clashes', $calls, $calls === 1 ? 'call' : 'calls',
            ), 'rota');
        }
    }
}
