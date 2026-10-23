<?php

namespace App\Actions\Identity;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateUserRoles
{
    /**
     * @param  list<string>  $roles
     */
    public function handle(User $admin, User $target, array $roles): void
    {
        Gate::forUser($admin)->authorize('update', $target);

        Validator::make(['roles' => $roles], [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(Role::class)],
        ], ['roles.required' => 'Give them at least one role.', 'roles.min' => 'Give them at least one role.'])->validate();

        $before = $target->getRoleNames()->sort()->values()->all();
        $target->syncRoles($roles);
        $after = $target->getRoleNames()->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        activity('identity')
            ->causedBy($admin)
            ->performedOn($target)
            ->withProperties(['old' => ['roles' => $before], 'attributes' => ['roles' => $after]])
            ->event('roles-changed')
            ->log("Changed roles for {$target->name}");
    }
}
