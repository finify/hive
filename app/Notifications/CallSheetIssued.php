<?php

namespace App\Notifications;

use App\Support\Display;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The call sheet for a day, attached as a PDF.
 */
class CallSheetIssued extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $eventName,
        public readonly string $date,
        public readonly string $pdf,
        public readonly string $filename,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Call sheet: {$this->code}, ".Display::date($this->date))
            ->line("The call sheet for {$this->eventName} on ".Display::date($this->date, weekday: true).' is attached.')
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'booking', 'title' => "Call sheet for {$this->code}", 'body' => $this->eventName.', '.Display::date($this->date).'. Sent by email.', 'link' => 'my-work'];
    }
}
