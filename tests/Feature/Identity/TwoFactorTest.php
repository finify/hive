<?php

use App\Enums\Role;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

it('challenges a user with two-factor and accepts a valid code', function () {
    $this->post('/login', ['email' => 'sam@lumenco.test', 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertOk()->assertSee('Demo: the authenticator shows');

    $code = app(Google2FA::class)->getCurrentOtp('HIVEDEMOSAMOKAFR');

    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect(route('admin.users'));
    $this->assertAuthenticatedAs(persona('sam'));
});

it('rejects a wrong two-factor code', function () {
    $this->post('/login', ['email' => 'grace@lumenco.test', 'password' => 'password']);

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
});

it('does not show the demo code outside demo mode', function () {
    config(['hive.demo.enabled' => false]);
    $this->post('/login', ['email' => 'sam@lumenco.test', 'password' => 'password']);

    $this->get('/two-factor-challenge')->assertOk()->assertDontSee('Demo: the authenticator shows');
});

it('makes required roles set up two-factor before using Hive', function (Role $role, bool $required) {
    $user = User::factory()->withRole($role)->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $required ? $response->assertRedirect(route('security')) : $response->assertOk();
})->with([
    'super admin' => [Role::SuperAdmin, true],
    'operations director' => [Role::OperationsDirector, true],
    'finance' => [Role::Finance, true],
    'HR' => [Role::Hr, true],
    'producer' => [Role::Producer, false],
    'freelancer' => [Role::Freelancer, false],
]);

it('lets required roles reach the security page to enrol', function () {
    $user = User::factory()->withRole(Role::Finance)->create();

    $this->actingAs($user)->get('/settings/security')->assertOk()->assertSee('Set up two-factor');
});
