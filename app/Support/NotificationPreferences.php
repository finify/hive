<?php

namespace App\Support;

use App\Models\Person;
use App\Models\User;

/**
 * Which notices someone also wants by email (section 5.10). In-app notices
 * are always on. With the daily digest on, emails for the chosen types
 * arrive once a day in one message instead of one by one.
 */
final class NotificationPreferences
{
    /** Notice types, as Actions pass them to Workspace::notify(), with their labels. */
    public const TYPES = [
        'booking' => 'Bookings, releases and travel',
        'clash' => 'Clashes on your events',
        'approval' => 'Things waiting for your approval',
        'timesheet' => 'Timesheets returned or approved',
        'leave' => 'Leave decisions and TOIL',
        'document' => 'Documents expiring',
        'po' => 'Purchase orders',
        'invoice' => 'Invoices and disputes',
        'billing' => 'Client billing',
        'people' => 'Onboarding and offboarding',
        'stage' => 'Event stage changes',
    ];

    /**
     * @param  array<string, bool>  $email
     */
    public function __construct(
        public readonly array $email,
        public readonly bool $digest,
    ) {}

    public static function of(object $notifiable): self
    {
        $person = match (true) {
            $notifiable instanceof Person => $notifiable,
            $notifiable instanceof User && $notifiable->person_id !== null => Person::query()->find($notifiable->person_id),
            default => null,
        };

        $stored = $person !== null ? ($person->notification_preferences ?? []) : [];
        $email = [];

        foreach (array_keys(self::TYPES) as $type) {
            $email[$type] = (bool) ($stored['email'][$type] ?? true);
        }

        return new self($email, (bool) ($stored['digest'] ?? false));
    }

    public function emails(string $type): bool
    {
        return $this->email[$type] ?? true;
    }

    /**
     * Channels for a notice of this type: always the bell; email now unless
     * the person turned it off or gets it in the digest.
     *
     * @return list<string>
     */
    public function channels(string $type): array
    {
        return $this->emails($type) && ! $this->digest ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array{email: array<string, bool>, digest: bool}
     */
    public function toArray(): array
    {
        return ['email' => $this->email, 'digest' => $this->digest];
    }
}
