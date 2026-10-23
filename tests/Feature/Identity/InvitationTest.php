<?php

use App\Actions\Identity\AcceptInvitation;
use App\Actions\Identity\InviteUser;
use App\Livewire\Admin\Users;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('lets an admin invite someone, who joins with the right role (phase 0)', function () {
    Notification::fake();
    $token = null;

    Livewire::actingAs(persona('sam'))
        ->test(Users::class)
        ->set('inviteEmail', 'new.producer@lumenco.test')
        ->set('inviteRole', 'producer')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n, array $channels, object $notifiable) use (&$token) {
        $mail = $n->toMail($notifiable);
        $token = basename(parse_url($mail->actionUrl, PHP_URL_PATH));

        return $notifiable->routes['mail'] === 'new.producer@lumenco.test';
    });

    $invitation = Invitation::query()->firstOrFail();
    expect($invitation->token)->not->toBe($token)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    auth()->guard('web')->logout();

    $this->get(route('invitations.show', $token))->assertOk()->assertSee('Join Hive')->assertSee('Producer');

    $this->post(route('invitations.accept', $token), ['name' => 'Nia Patel', 'password' => 'a-strong-passphrase', 'password_confirmation' => 'a-strong-passphrase'])
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'new.producer@lumenco.test')->firstOrFail();
    expect($user->hasRole('producer'))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('refuses expired and used invitations', function () {
    $expired = Invitation::factory()->expired()->create(['token' => hash('sha256', 'expired-token')]);

    $this->get(route('invitations.show', 'expired-token'))->assertOk()->assertSee('This invitation has expired');
    $this->post(route('invitations.accept', 'expired-token'), ['name' => 'X', 'password' => 'a-strong-passphrase', 'password_confirmation' => 'a-strong-passphrase'])
        ->assertSessionHasErrors('token');

    expect(User::query()->where('email', $expired->email)->exists())->toBeFalse();
});

it('does not invite someone who already has an account', function () {
    Notification::fake();

    expect(fn () => app(InviteUser::class)->handle(persona('sam'), 'kofi@freelance.test', 'freelancer'))
        ->toThrow(ValidationException::class);
    Notification::assertNothingSent();
});

it('lets only users.manage invite', function (string $who) {
    app(InviteUser::class)->handle(persona($who), 'someone@lumenco.test', 'employee');
})->with(['hannah', 'grace', 'kofi'])->throws(AuthorizationException::class);

it('stores only a hash of the token', function () {
    Notification::fake();
    $invitation = app(InviteUser::class)->handle(persona('leah'), 'starter@lumenco.test', 'employee');

    expect(strlen($invitation->token))->toBe(64)
        ->and(AcceptInvitation::find($invitation->token))->toBeNull();
});
