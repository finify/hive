<?php

namespace App\Actions\Settings;

use App\Models\Person;
use App\Models\User;
use App\Support\NotificationPreferences;
use Illuminate\Validation\ValidationException;

/**
 * Saves which notices the signed-in person also wants by email, and whether
 * to get them as a daily digest. Only for yourself.
 */
class SaveNotificationPreferences
{
    /**
     * @param  array<string, mixed>  $email  type => bool
     */
    public function handle(User $user, array $email, bool $digest): NotificationPreferences
    {
        $person = $user->person_id ? Person::query()->find($user->person_id) : null;

        if ($person === null) {
            throw ValidationException::withMessages(['email' => 'Your sign-in isn’t linked to a person in Hive, so there is nothing to set.']);
        }

        $old = NotificationPreferences::of($person);
        $new = new NotificationPreferences(
            array_map(fn (string $type) => (bool) ($email[$type] ?? false), array_combine(array_keys(NotificationPreferences::TYPES), array_keys(NotificationPreferences::TYPES))),
            $digest,
        );

        $person->forceFill(['notification_preferences' => $new->toArray()])->save();

        activity('people')
            ->causedBy($user)
            ->performedOn($person)
            ->withProperties(['ref' => 'P-'.$person->id, 'old' => $old->toArray(), 'attributes' => $new->toArray()])
            ->event('notification-preferences-saved')
            ->log('Changed their notification settings');

        return $new;
    }
}
