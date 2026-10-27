<?php

namespace App\Jobs;

use App\Demo\DemoData;
use App\Models\AiRequest;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Person;
use App\Models\RetentionFlag;
use App\Models\Timesheet;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * On the first of each month, puts records past their retention period up
 * for review (section 5.14). Nothing is deleted here: HR decides on the
 * Data retention page. A record is flagged once.
 */
class FlagRecordsPastRetention implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Retried with backoff: a minute, then five. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function handle(DemoData $data): int
    {
        $today = config('hive.demo.enabled') ? $data->today() : CarbonImmutable::today();
        $r = (array) config('hive.retention');
        $flagged = 0;

        $flag = function (string $type, int $id, string $category, string $label, CarbonImmutable $on) use (&$flagged) {
            $flag = RetentionFlag::query()->firstOrCreate(
                ['subject_type' => $type, 'subject_id' => $id, 'category' => $category],
                ['label' => $label, 'past_retention_on' => $on->toDateString()],
            );
            $flagged += $flag->wasRecentlyCreated ? 1 : 0;
        };

        foreach (Timesheet::query()->whereDate('week_start', '<', $today->subYears((int) $r['timesheets_years'])->toDateString())->get() as $t) {
            $flag(Timesheet::class, $t->id, 'timesheet', 'Timesheet, week of '.Display::date($t->week_start), CarbonImmutable::parse($t->week_start)->addYears((int) $r['timesheets_years']));
        }

        foreach (Expense::query()->whereDate('date', '<', $today->subYears((int) $r['expenses_years'])->toDateString())->get() as $e) {
            $flag(Expense::class, $e->id, 'expense', "Expense: {$e->description}", CarbonImmutable::parse($e->date)->addYears((int) $r['expenses_years']));
        }

        foreach (Person::query()->whereNull('anonymised_at')->whereNotNull('end_date')->whereDate('end_date', '<', $today->subYears((int) $r['leavers_years'])->toDateString())->get() as $p) {
            $flag(Person::class, $p->id, 'leaver', "{$p->first_name} {$p->last_name}, left ".Display::date($p->end_date), CarbonImmutable::parse($p->end_date)->addYears((int) $r['leavers_years']));
        }

        foreach (Document::query()->whereNotNull('expires_at')->whereDate('expires_at', '<', $today->subYears((int) $r['expired_documents_years'])->toDateString())->get() as $d) {
            $flag(Document::class, $d->id, 'document', "{$d->title}, expired ".Display::date($d->expires_at), CarbonImmutable::parse($d->expires_at)->addYears((int) $r['expired_documents_years']));
        }

        foreach (AiRequest::query()->where('created_at', '<', $today->subMonths((int) $r['ai_requests_months']))->get() as $a) {
            $flag(AiRequest::class, $a->id, 'ai-request', 'Ask Hive question: '.mb_strimwidth($a->prompt, 0, 60, '…'), CarbonImmutable::parse($a->created_at)->addMonths((int) $r['ai_requests_months']));
        }

        return $flagged;
    }
}
