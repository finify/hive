<?php

namespace App\Support\Resourcing;

use App\Data\Booking;
use App\Data\Clash;

/**
 * Finds clashes: the same person held by two assignments on overlapping dates,
 * or an assignment overlapping approved leave or marked unavailability.
 *
 * Callers pass only bookings that hold the person (released and declined
 * assignments, and leave that is not approved, are left out).
 */
final class ClashDetector
{
    /**
     * @param  iterable<Booking>  $bookings
     * @return list<Clash>
     */
    public function detect(iterable $bookings): array
    {
        $byPerson = [];

        foreach ($bookings as $booking) {
            $byPerson[$booking->personId][] = $booking;
        }

        $clashes = [];

        foreach ($byPerson as $personBookings) {
            usort($personBookings, fn (Booking $a, Booking $b) => $a->dates->start <=> $b->dates->start);
            $count = count($personBookings);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $personBookings[$i];
                    $b = $personBookings[$j];

                    if ($b->dates->start->greaterThan($a->dates->end)) {
                        break;
                    }

                    if ($a->kind !== Booking::ASSIGNMENT && $b->kind !== Booking::ASSIGNMENT) {
                        continue;
                    }

                    if ($a->kind === Booking::ASSIGNMENT && $b->kind === Booking::ASSIGNMENT && $a->eventId === $b->eventId) {
                        continue;
                    }

                    $overlap = $a->dates->intersect($b->dates);

                    if ($overlap !== null) {
                        $clashes[] = new Clash($a->personId, $overlap, $a, $b);
                    }
                }
            }
        }

        return $clashes;
    }

    /**
     * Would adding this booking create a clash with any existing one?
     *
     * @param  iterable<Booking>  $existing
     * @return list<Clash>
     */
    public function clashesFor(Booking $candidate, iterable $existing): array
    {
        $relevant = [$candidate];

        foreach ($existing as $booking) {
            if ($booking->personId === $candidate->personId && $booking->key() !== $candidate->key()) {
                $relevant[] = $booking;
            }
        }

        return array_values(array_filter(
            $this->detect($relevant),
            fn (Clash $clash) => $clash->first->key() === $candidate->key() || $clash->second->key() === $candidate->key(),
        ));
    }
}
