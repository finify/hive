<?php

namespace App\Notifications;

use App\Models\Document;
use App\Support\Display;
use App\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiring extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Document $document,
        public readonly string $personName,
        public readonly int $days,
        public readonly bool $forThePerson,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::of($notifiable)->channels('document');
    }

    public function title(): string
    {
        return $this->forThePerson
            ? "Your {$this->document->title} expires in {$this->days} days"
            : "{$this->personName}: {$this->document->title} expires in {$this->days} days";
    }

    public function body(): string
    {
        $date = Display::date($this->document->expires_at);

        return $this->forThePerson
            ? "It expires on {$date}. Upload the renewed document in Hive so producers can keep confirming your bookings."
            : "It expires on {$date}. Bookings can’t be confirmed once a mandatory document has expired.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action('Open Hive', route('people.show', $this->document->documentable_id).'?tab=documents');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document',
            'title' => $this->title(),
            'body' => $this->body(),
            'person_id' => $this->document->documentable_id,
            'document_id' => $this->document->id,
            'link' => 'people',
        ];
    }
}
