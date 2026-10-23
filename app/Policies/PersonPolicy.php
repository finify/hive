<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;

class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['people.view', 'people.view_basic']);
    }

    public function create(User $user): bool
    {
        return $user->can('people.manage');
    }

    public function update(User $user, Person $person): bool
    {
        return $user->can('people.manage');
    }

    /**
     * Restricted fields (emergency contact, passport, dietary and access
     * needs, confidential notes) are for HR and the person only.
     */
    public function viewRestricted(User $user, Person $person): bool
    {
        return $user->can('people.view_restricted') || $user->person_id === $person->id;
    }

    public function editRestricted(User $user, Person $person): bool
    {
        return $user->can('people.view_restricted') && $user->can('people.manage');
    }

    public function viewRates(User $user, Person $person): bool
    {
        return $user->can('rates.view') || $user->person_id === $person->id;
    }

    /**
     * Adding a new effective-dated rate is finance's job (rates.edit).
     */
    public function addRate(User $user, Person $person): bool
    {
        return $user->can('rates.edit');
    }

    /**
     * HR records documents for anyone; people upload their own.
     */
    public function uploadDocument(User $user, Person $person): bool
    {
        return $user->can('documents.manage') || $user->person_id === $person->id;
    }
}
