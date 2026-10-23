<?php

use App\Models\User;

it('shows the sign-in page with demo personas in demo mode', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in to Hive')
        ->assertSee('Walk through Hive as someone at the agency')
        ->assertSee('Hannah Clarke')
        ->assertSee('Kofi Mensah');
});

it('hides the personas when demo mode is off', function () {
    config(['hive.demo.enabled' => false]);

    $this->get('/login')->assertOk()->assertDontSee('Walk through Hive as someone at the agency')->assertDontSee('kofi@freelance.test');
});

it('has no public registration', function () {
    $this->get('/register')->assertNotFound();
});

it('signs a persona in and lands them on their walkthrough', function () {
    $this->post('/login', ['email' => 'hannah@lumenco.test', 'password' => 'password'])
        ->assertRedirect(route('rota'));

    $this->assertAuthenticatedAs(persona('hannah'));
    expect(persona('hannah')->last_login_at)->not->toBeNull();
});

it('logs successful and failed sign-ins', function () {
    $this->post('/login', ['email' => 'jamie@lumenco.test', 'password' => 'wrong']);
    $this->post('/login', ['email' => 'jamie@lumenco.test', 'password' => 'password']);

    $this->assertDatabaseHas('activity_log', ['event' => 'sign-in-failed', 'log_name' => 'identity']);
    $this->assertDatabaseHas('activity_log', ['event' => 'signed-in', 'causer_id' => persona('jamie')->id]);
});

it('refuses deactivated users', function () {
    $user = User::factory()->inactive()->create(['password' => 'password']);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been deactivated. Contact HR if you think this is a mistake.']);
    $this->assertGuest();
});

it('signs out a user deactivated mid-session on their next request', function () {
    $user = persona('jamie');
    $this->actingAs($user)->get('/dashboard')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('sends guests to sign in', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
