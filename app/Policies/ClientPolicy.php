<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('directory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('directory.manage');
    }

    public function update(User $user, Client $model): bool
    {
        return $user->can('directory.manage');
    }
}
