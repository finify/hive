<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Venue;

class VenuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('directory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('directory.manage');
    }

    public function update(User $user, Venue $model): bool
    {
        return $user->can('directory.manage');
    }
}
