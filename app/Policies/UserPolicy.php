<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function invite(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('users.manage');
    }

    /**
     * Admins cannot lock themselves out.
     */
    public function deactivate(User $user, User $target): bool
    {
        return $user->can('users.manage') && $user->isNot($target);
    }
}
