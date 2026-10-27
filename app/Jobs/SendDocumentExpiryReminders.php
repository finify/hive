<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Person;
use App\Models\User;
use App\Notifications\DocumentExpiring;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Daily at 07:00: reminds the person and HR 30 and 7 days before a document
 * expires. Each reminder is sent once (documents.reminded_days), so running
 * the job twice in a day sends nothing new.
 */
class SendDocumentExpiryReminders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Not retried: a second run would email the first people again. Failures show on the Queue page. */
    public int $tries = 1;

    public function handle(): void
    {
        $today = CarbonImmutable::today();
        $hr = User::query()->active()->role('hr')->get();
        $windows = config('hive.documents.reminder_days', [30, 7]);
        rsort($windows);

        Document::query()
            ->where('documentable_type', Person::class)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today)
            ->whereDate('expires_at', '<=', $today->addDays((int) max($windows)))
            ->with('documentable')
            ->each(function (Document $document) use ($today, $hr, $windows) {
                $person = $document->documentable;

                if (! $person instanceof Person || ! $person->is_active || $document->expires_at === null) {
                    return;
                }

                $daysLeft = (int) $today->diffInDays($document->expires_at->toImmutable()->startOfDay());
                $window = collect($windows)->filter(fn ($w) => $daysLeft <= $w)->min();

                if ($window === null || ($document->reminded_days !== null && $document->reminded_days <= $window)) {
                    return;
                }

                $personUser = User::query()->active()->where('person_id', $person->id)->first();

                if ($personUser) {
                    $personUser->notify(new DocumentExpiring($document, $person->displayName(), $daysLeft, true));
                }

                Notification::send($hr, new DocumentExpiring($document, $person->displayName(), $daysLeft, false));

                $document->forceFill(['reminded_days' => $window])->saveQuietly();
            });
    }
}
