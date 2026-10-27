<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email a day with the notices someone chose to get by email, for
 * people who prefer a digest.
 */
class DailyDigest extends Notification
{
    use Queueable;

    /**
     * @param  list<array{title: string, body: string}>  $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->items);
        $mail = (new MailMessage)->subject($count === 1 ? 'Hive: 1 update since yesterday' : "Hive: {$count} updates since yesterday");

        foreach (array_slice($this->items, 0, 20) as $item) {
            $mail->line("**{$item['title']}** {$item['body']}");
        }

        if ($count > 20) {
            $mail->line('And '.($count - 20).' more in Hive.');
        }

        return $mail->action('Open your notifications', route('notifications'));
    }
}
