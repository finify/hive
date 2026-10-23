<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A purchase order emailed to the supplier, with the PDF attached.
 */
class PurchaseOrderIssued extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $pdf,
        public readonly string $organisation,
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
            ->subject("Purchase order {$this->code} from {$this->organisation}")
            ->line("Please find purchase order {$this->code} attached. Quote the PO number on your invoice.")
            ->attachData($this->pdf, "{$this->code}.pdf", ['mime' => 'application/pdf']);
    }
}
