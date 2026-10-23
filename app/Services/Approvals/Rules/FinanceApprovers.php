<?php

namespace App\Services\Approvals\Rules;

use App\Models\User;

/**
 * People in the finance team who hold a permission, by person id.
 */
trait FinanceApprovers
{
    /**
     * @return list<int>
     */
    protected function financeHolding(string $permission): array
    {
        return User::query()->active()->whereNotNull('person_id')->role('finance')->get()
            ->filter(fn (User $u) => $u->can($permission))
            ->map(fn (User $u) => (int) $u->person_id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function withRole(string $role): array
    {
        return User::query()->active()->whereNotNull('person_id')->role($role)->pluck('person_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
