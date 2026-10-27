<?php

namespace App\Notifications;

use App\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short notice for someone's bell and inbox: a booking request, a release,
 * a clash, an approval. Sent to the person, so freelancers without a Hive
 * sign-in still get the email and keep the history if they get one later.
 */
class HiveNotice extends Notification
{
    use Queueable;

    /** Where "Open" goes, by the link key the Actions use. */
    public const ROUTES = [
        'rota' => 'rota', 'approvals' => 'approvals', 'supplier-invoices' => 'supplier-invoices', 'people' => 'people.index',
        'my-work' => 'my-work', 'events' => 'events.index', 'timesheets' => 'timesheets', 'clashes' => 'clashes', 'crew-calls' => 'crew-calls',
        'leave' => 'leave', 'onboarding' => 'onboarding',
    ];

    public function __construct(
        public readonly int $personId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly string $link,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::of($notifiable)->channels($this->type);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->body);

        return isset(self::ROUTES[$this->link]) ? $mail->action('Open Hive', route(self::ROUTES[$this->link])) : $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'title' => $this->title, 'body' => $this->body, 'link' => $this->link, 'person_id' => $this->personId];
    }
}
