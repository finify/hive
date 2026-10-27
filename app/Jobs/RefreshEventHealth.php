<?php

namespace App\Jobs;

use App\Demo\Workspace;
use App\Enums\EventStage;
use App\Enums\HealthStatus;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Each morning, works out every active event's budget health and tells the
 * producer and the operations director when one has slipped since the day
 * before (on track to watch, or anything to at risk). Health itself is
 * always calculated live; this job only notices the change.
 */
class RefreshEventHealth implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Not retried: people would be told twice. */
    public int $tries = 1;

    private const RANK = ['on-track' => 0, 'no-budget' => 0, 'watch' => 1, 'at-risk' => 2];

    public function handle(Workspace $workspace): int
    {
        $workspace->forget();
        $directors = User::query()->active()->whereNotNull('person_id')->permission('budgets.approve')->pluck('person_id')->map(fn ($id) => (int) $id)->all();
        $told = 0;

        foreach ($workspace->events() as $event) {
            if (! in_array(EventStage::from($event['stage']), [EventStage::Confirmed, EventStage::PreProduction, EventStage::Live, EventStage::Wrap], true)) {
                continue;
            }

            $health = $workspace->health($event['id']);

            if ($health === null) {
                continue;
            }

            $key = "event-health.{$event['id']}";
            $before = Cache::get($key);
            Cache::forever($key, $health->value);

            if ($before === null || self::RANK[$health->value] <= (self::RANK[$before] ?? 0)) {
                continue;
            }

            $f = $workspace->financials($event['id']);
            $body = $f ? "Forecast {$f->forecast()->format(false)} against {$f->budget->format(false)} ({$f->forecastPercentOfBudget()}%), margin {$f->marginPercent()}%." : '';

            foreach (array_unique(array_filter([$event['producer_id'], ...$directors])) as $personId) {
                $workspace->notify((int) $personId, 'budget', "{$event['code']} is now ".mb_strtolower($health === HealthStatus::AtRisk ? 'at risk' : $health->label()), $body, 'events');
                $told++;
            }
        }

        return $told;
    }
}
