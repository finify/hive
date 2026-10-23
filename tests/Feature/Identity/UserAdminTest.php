<?php

use App\Actions\Identity\SetUserActive;
use App\Actions\Identity\UpdateUserRoles;
use App\Livewire\Admin\Users;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('assigns roles and logs the permission change (phase 0)', function () {
    $jamie = persona('jamie');

    Livewire::actingAs(persona('sam'))
        ->test(Users::class)
        ->call('editRoles', $jamie->id)
        ->set('roles', ['employee', 'head-of-department'])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($jamie->fresh()->hasRole('head-of-department'))->toBeTrue();

    $log = Activity::query()->where('event', 'roles-changed')->latest('id')->firstOrFail();
    expect($log->properties['old']['roles'])->toBe(['employee'])
        ->and($log->properties['attributes']['roles'])->toBe(['employee', 'head-of-department']);
});

it('requires at least one role', function () {
    Livewire::actingAs(persona('sam'))
        ->test(Users::class)
        ->call('editRoles', persona('jamie')->id)
        ->set('roles', [])
        ->call('saveRoles')
        ->assertHasErrors('roles');
});

it('revokes sessions immediately when a user is deactivated', function () {
    $jamie = persona('jamie');
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $jamie->id, 'payload' => '', 'last_activity' => time()]);

    app(SetUserActive::class)->handle(persona('sam'), $jamie, false);

    expect($jamie->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $jamie->id)->count())->toBe(0);
    $this->assertDatabaseHas('activity_log', ['event' => 'deactivated', 'subject_id' => $jamie->id]);
});

it('stops admins deactivating themselves', function () {
    app(SetUserActive::class)->handle(persona('sam'), persona('sam'), false);
})->throws(AuthorizationException::class);

it('blocks role changes from users without users.manage', function () {
    app(UpdateUserRoles::class)->handle(persona('priya'), persona('jamie'), ['super-admin']);
})->throws(AuthorizationException::class);
