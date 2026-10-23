<?php

namespace App\Actions\Time;

use App\Demo\Rows;
use App\Demo\Workspace;
use App\Support\Money;

/**
 * Turns an approved freelancer timesheet entry into an Actual cost on the
 * event's crew budget line, at the day rate effective on the date worked.
 *
 * Idempotent: a second call for the same entry posts nothing.
 */
class PostTimesheetCost
{
    public function __construct(private readonly Workspace $workspace) {}

    public function handle(int $personId, int $entryId): ?Money
    {
        $found = $this->workspace->findEntry($entryId);

        if (! $found) {
            return null;
        }

        [, $entry] = $found;
        $cost = $this->workspace->entryCost($personId, $entry);

        if ($cost === null || ! $entry['event_id']) {
            return null;
        }

        $alreadyPosted = $this->workspace->costEntries()->contains(
            fn ($c) => ($c['source']['type'] ?? null) === 'timesheet-entry' && ($c['source']['id'] ?? null) === $entryId,
        );

        if ($alreadyPosted) {
            return null;
        }

        $crewLine = Rows::of($this->workspace->budget($entry['event_id'])['lines'] ?? [])->firstWhere('category', 'crew');

        $this->workspace->ledger->push('cost_entries', [
            'id' => $this->workspace->ledger->nextId('cost_entries', 100_000),
            'event_id' => $entry['event_id'],
            'budget_line_id' => $crewLine['id'] ?? null,
            'source' => ['type' => 'timesheet-entry', 'id' => $entryId, 'ref' => 'TE-'.$entryId],
            'kind' => 'actual',
            'amount_minor' => $cost->minor,
            'currency' => $cost->currency,
            'fx_rate' => null,
            'amount_gbp_minor' => $cost->minor,
            'posted_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        $this->workspace->forget();

        return $cost;
    }
}
