<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('directory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('directory.manage');
    }

    public function update(User $user, Supplier $model): bool
    {
        return $user->can('directory.manage');
    }
}
