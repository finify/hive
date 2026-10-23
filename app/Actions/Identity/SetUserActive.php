<?php

namespace App\Actions\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Deactivating keeps the user's history but signs them out everywhere at once.
 */
class SetUserActive
{
    public function handle(User $admin, User $target, bool $active): void
    {
        Gate::forUser($admin)->authorize($active ? 'update' : 'deactivate', $target);

        if ($target->is_active === $active) {
            return;
        }

        $target->forceFill(['is_active' => $active, 'remember_token' => null])->save();

        if (! $active && config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
        }

        activity('identity')
            ->causedBy($admin)
            ->performedOn($target)
            ->withProperties(['old' => ['is_active' => ! $active], 'attributes' => ['is_active' => $active]])
            ->event($active ? 'reactivated' : 'deactivated')
            ->log(($active ? 'Reactivated ' : 'Deactivated ').$target->name);
    }
}
