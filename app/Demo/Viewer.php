<?php

namespace App\Demo;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the signed-in user may see. Every list and every record shown in the
 * demo screens, exports and Ask Hive tool results passes through here, so a
 * field that is not allowed is removed before it reaches a view or a model.
 */
class Viewer
{
    /** Fields only people with rates.view (or the person themselves) receive. */
    public const RATE_FIELDS = ['day_rates', 'agreed_rate_minor', 'budgeted_rate_minor', 'payment_terms_days', 'vat_number', 'company_name'];

    /** Fields only HR (or the person themselves) receive. */
    public const RESTRICTED_FIELDS = ['restricted', 'notes', 'phone'];

    public function __construct(
        public readonly User $user,
        private readonly Workspace $workspace,
    ) {}

    public function personId(): ?int
    {
        return $this->user->person_id;
    }

    public function can(string $permission): bool
    {
        return $this->user->can($permission);
    }

    public function isSelf(?int $personId): bool
    {
        return $personId !== null && $personId === $this->personId();
    }

    // ------------------------------------------------------------------ people

    public function canSeeRatesOf(?int $personId): bool
    {
        return $this->can('rates.view') || $this->isSelf($personId);
    }

    public function canSeeRestrictedOf(?int $personId): bool
    {
        return $this->can('people.view_restricted') || $this->isSelf($personId);
    }

    public function canSeeDirectory(): bool
    {
        return $this->can('people.view') || $this->can('people.view_basic');
    }

    public function canSeePerson(int $personId): bool
    {
        return $this->canSeeDirectory() || $this->isSelf($personId);
    }

    /**
     * A person record with the fields this viewer may not see removed.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    public function presentPerson(array $person): array
    {
        $id = (int) $person['id'];

        if (! $this->canSeeRatesOf($id)) {
            $person = array_diff_key($person, array_flip(self::RATE_FIELDS));
        }

        if (! $this->canSeeRestrictedOf($id)) {
            $person = array_diff_key($person, array_flip(self::RESTRICTED_FIELDS));
        }

        return $person;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function people(): Collection
    {
        if (! $this->canSeeDirectory()) {
            return $this->workspace->people()->filter(fn ($p) => $this->isSelf($p['id']))->map(fn ($p) => $this->presentPerson($p));
        }

        return $this->workspace->people()->map(fn ($p) => $this->presentPerson($p));
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    public function presentAssignment(array $assignment): array
    {
        if (! $this->canSeeRatesOf((int) $assignment['person_id'])) {
            unset($assignment['agreed_rate_minor']);
        }

        return $assignment;
    }

    /**
     * The leave label others see. Sensitive types (sickness, parental,
     * compassionate) show as "Absent" except to HR, the line manager and the
     * person.
     *
     * @param  array<string, mixed>  $leave
     */
    public function leaveLabel(array $leave): string
    {
        $type = $this->workspace->leaveType($leave['leave_type']);
        $person = $this->workspace->person((int) $leave['person_id']);

        $mayKnow = $this->can('leave.view_all')
            || $this->isSelf((int) $leave['person_id'])
            || ($person && $this->isSelf($person['line_manager_id'] ?? null));

        if (($type['is_sensitive'] ?? false) && ! $mayKnow) {
            return 'Absent';
        }

        return $type['name'] ?? 'Leave';
    }

    // ------------------------------------------------------------------ events

    public function canSeeAllEvents(): bool
    {
        return $this->can('events.view_all');
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function canSeeEvent(array $event): bool
    {
        if (! $this->can('events.view')) {
            return false;
        }

        if ($this->canSeeAllEvents()) {
            return true;
        }

        return $this->workspace->isOnEvent((int) $event['id'], $this->personId());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function events(): Collection
    {
        return $this->workspace->events()->filter(fn ($event) => $this->canSeeEvent($event));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function isProducerOf(array $event): bool
    {
        return $this->isSelf((int) $event['producer_id']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function canSeeBudget(array $event): bool
    {
        if (! $this->can('budgets.view') || ! $this->canSeeEvent($event)) {
            return false;
        }

        return $this->can('budgets.view_all') || $this->isProducerOf($event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function canManageCrew(array $event): bool
    {
        return $this->can('crew.manage_all') || ($this->can('crew.manage') && $this->isProducerOf($event));
    }

    // ------------------------------------------------------------------ rota

    public function canSeeWholeRota(): bool
    {
        return $this->can('rota.view_all');
    }

    /**
     * @return list<int> person ids shown on the rota
     */
    public function rotaPeople(): array
    {
        if ($this->canSeeWholeRota()) {
            return $this->workspace->people()->keys()->all();
        }

        return $this->personId() ? [$this->personId()] : [];
    }
}
