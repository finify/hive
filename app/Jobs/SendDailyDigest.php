<?php

namespace App\Jobs;

use App\Models\Person;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Support\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Each morning, people who chose the daily digest get one email with the
 * last day's notices of the types they want by email. Nothing is sent to
 * someone with nothing new.
 */
class SendDailyDigest implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $since = now()->subDay();

        foreach (Person::query()->whereNotNull('notification_preferences')->get() as $person) {
            $prefs = NotificationPreferences::of($person);

            if (! $prefs->digest) {
                continue;
            }

            $userId = User::query()->where('person_id', $person->id)->value('id');

            $items = DB::table('notifications')
                ->where('created_at', '>=', $since)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('notifiable_type', Person::class)->where('notifiable_id', $person->id))
                    ->when($userId, fn ($q) => $q->orWhere(fn ($q) => $q->where('notifiable_type', User::class)->where('notifiable_id', $userId))))
                ->orderBy('created_at')
                ->pluck('data')
                ->map(fn ($json) => json_decode((string) $json, true))
                ->filter(fn ($d) => is_array($d) && isset($d['title']) && $prefs->emails((string) ($d['type'] ?? '')))
                ->map(fn ($d) => ['title' => (string) $d['title'], 'body' => (string) ($d['body'] ?? '')])
                ->values()->all();

            if ($items !== []) {
                $person->notify(new DailyDigest($items));
            }
        }
    }
}
