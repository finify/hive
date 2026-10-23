<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Support\Display;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You’ve been invited to Hive')
            ->greeting('You’ve been invited to Hive')
            ->line($this->invitation->inviter->name.' has invited you to join Hive, Lumen & Co Live’s operations platform, as '.$this->invitation->role->label().'.')
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This link expires on '.Display::dateTime($this->invitation->expires_at).'.');
    }
}
