<?php

namespace App\Actions\Gdpr;

use App\Demo\Workspace;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Erasure (UK GDPR article 17) for someone who has left: their personal
 * fields are replaced, restricted details, documents, skills, preferences
 * and notices removed, and their sign-in closed. Timesheets, costs,
 * invoices and expenses stay, attached to "Former staff member 12", so the
 * books still add up. The audit log is kept as the record of processing.
 */
class AnonymisePerson
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @return list<string>
     */
    public function blockers(int $personId): array
    {
        $person = Person::withTrashed()->findOrFail($personId);
        $today = $this->workspace->today()->toDateString();
        $blockers = [];

        if ($person->anonymised_at !== null) {
            $blockers[] = 'Already anonymised.';
        }

        if ($person->is_active && ($person->end_date === null || $person->end_date->toDateString() >= $today)) {
            $blockers[] = 'Only people who have left can be anonymised. Set a leaving date in the past or deactivate them first.';
        }

        if (Assignment::query()->where('person_id', $personId)->whereDate('end_date', '>=', $today)
            ->whereIn('status', [AssignmentStatus::Confirmed, AssignmentStatus::FirstHold, AssignmentStatus::SecondHold])->exists()) {
            $blockers[] = 'They still have bookings from today on. Release them first.';
        }

        return $blockers;
    }

    public function handle(User $user, int $personId, string $reason): void
    {
        Gate::forUser($user)->authorize('people.manage');
        Gate::forUser($user)->authorize('people.view_restricted');

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Record why, for example the erasure request’s reference.']);
        }

        if ($blockers = $this->blockers($personId)) {
            throw ValidationException::withMessages(['reason' => $blockers]);
        }

        $person = Person::withTrashed()->with('documents')->findOrFail($personId);
        $files = $person->documents->pluck('file_path')->filter()->all();

        DB::transaction(function () use ($person) {
            $id = $person->id;

            // Quietly: the activity log would otherwise record the old name.
            Person::withoutEvents(fn () => $person->forceFill([
                'first_name' => 'Former',
                'last_name' => "staff member {$id}",
                'preferred_name' => null, 'pronouns' => null,
                'email' => "anonymised-{$id}@invalid.hive",
                'phone' => null, 'notes' => null, 'languages' => [],
                'company_name' => null, 'vat_number' => null,
                'location_city' => 'Not recorded', 'notification_preferences' => null,
                'is_active' => false, 'anonymised_at' => now(),
            ])->save());

            $person->restricted()->delete();
            $person->skills()->detach();
            $person->documents()->delete();
            DB::table('notifications')->where('notifiable_type', Person::class)->where('notifiable_id', $id)->delete();

            $account = User::query()->where('person_id', $id)->first();

            if ($account) {
                DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $account->id)->delete();
                $account->forceFill([
                    'name' => "Former staff member {$id}", 'email' => "anonymised-{$id}@invalid.hive", 'is_active' => false,
                    'password' => bcrypt(bin2hex(random_bytes(16))), 'remember_token' => null,
                    'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
                    'calendar_token_hash' => null, 'entra_object_id' => null,
                ])->save();
                $account->syncRoles([]);
            }
        });

        Storage::disk(config('filesystems.documents_disk', 'local'))->delete($files);
        $this->workspace->forget();

        activity('gdpr')->causedBy($user)->performedOn($person)
            ->withProperties(['ref' => 'P-'.$personId, 'attributes' => ['reason' => $reason, 'documents_deleted' => count($files)]])
            ->event('anonymised')->log("Anonymised person {$personId}");
    }
}
