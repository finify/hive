<?php

namespace App\Jobs;

use App\Data\Booking;
use App\Demo\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs after a booking is made or changed: tells the producers of every
 * event involved in a clash for that person and those dates, apart from
 * whoever made the change (they saw the warning already).
 */
class DetectClashes implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $personId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $causedByPersonId = null,
    ) {}

    public function handle(Workspace $workspace): void
    {
        $workspace->forget();
        $told = [];

        foreach ($workspace->clashes() as $clash) {
            if ($clash->personId !== $this->personId || $clash->dates->end->toDateString() < $this->startDate || $clash->dates->start->toDateString() > $this->endDate) {
                continue;
            }

            foreach ([$clash->first, $clash->second] as $booking) {
                if ($booking->kind !== Booking::ASSIGNMENT || $booking->eventId === null) {
                    continue;
                }

                $producer = $workspace->event($booking->eventId)['producer_id'] ?? null;

                if ($producer === null || $producer === $this->causedByPersonId || isset($told[$producer])) {
                    continue;
                }

                $told[$producer] = true;
                $workspace->notify($producer, 'clash', $workspace->personName($this->personId).' is double-booked', "{$clash->first->label} and {$clash->second->label}, {$clash->dates->format()}. Resolve it from the clashes list.", 'clashes');
            }
        }
    }
}
