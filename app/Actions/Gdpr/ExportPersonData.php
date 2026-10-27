<?php

namespace App\Actions\Gdpr;

use App\Models\AiRequest;
use App\Models\Assignment;
use App\Models\Checklist;
use App\Models\Expense;
use App\Models\FreelancerInvoice;
use App\Models\LeaveAllowance;
use App\Models\LeaveRequest;
use App\Models\Person;
use App\Models\Timesheet;
use App\Models\ToilAccrual;
use App\Models\TravelBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use ZipArchive;

/**
 * Subject access request (UK GDPR article 15): everything Hive holds about
 * one person, as JSON plus their uploaded documents, in a ZIP. HR only, and
 * every export is logged.
 */
class ExportPersonData
{
    /**
     * @return string path to a temporary ZIP file; the caller deletes it
     */
    public function handle(User $user, int $personId): string
    {
        Gate::forUser($user)->authorize('people.view_restricted');
        Gate::forUser($user)->authorize('people.manage');

        $person = Person::withTrashed()->with(['restricted', 'skills', 'dayRates', 'documents'])->findOrFail($personId);
        $account = User::query()->where('person_id', $personId)->first();

        $data = [
            'exported_at' => now()->utc()->toIso8601String(),
            'exported_by' => $user->name,
            'person' => $person->makeVisible(['notes'])->only([
                'id', 'first_name', 'last_name', 'preferred_name', 'pronouns', 'email', 'phone', 'employment_type', 'job_title',
                'location_city', 'location_country', 'bank_holiday_region', 'start_date', 'end_date', 'company_name', 'vat_registered',
                'vat_number', 'payment_terms_days', 'languages', 'rating', 'notes', 'is_active', 'notification_preferences', 'anonymised_at',
            ]),
            'restricted' => $person->restricted?->makeVisible(['emergency_contact', 'passport_number'])->only([
                'emergency_contact', 'passport_number', 'passport_expiry', 'nationality', 'dietary_requirements', 'access_needs',
            ]),
            'account' => $account ? [
                'email' => $account->email, 'roles' => $account->getRoleNames()->all(), 'active' => $account->is_active,
                'last_sign_in' => $account->last_login_at?->toIso8601String(), 'two_factor' => $account->two_factor_confirmed_at !== null,
                'microsoft_sign_in' => $account->entra_object_id !== null,
            ] : null,
            'skills' => $person->skills->map(fn ($s) => ['skill' => $s->name, 'level' => $s->getRelationValue('pivot')->getAttribute('level')])->all(),
            'day_rates' => $person->dayRates->map->only(['role_name', 'amount_minor', 'currency', 'effective_from'])->all(),
            'documents' => $person->documents->map->only(['type', 'title', 'issued_at', 'expires_at', 'original_name'])->all(),
            'bookings' => Assignment::query()->where('person_id', $personId)->get()->map->only(['event_id', 'start_date', 'end_date', 'status', 'agreed_rate_minor', 'currency'])->all(),
            'leave' => LeaveRequest::query()->where('person_id', $personId)->get()->map->only(['leave_type', 'start_date', 'end_date', 'days', 'status', 'reason'])->all(),
            'leave_allowances' => LeaveAllowance::query()->where('person_id', $personId)->get()->map->only(['leave_year', 'days_allowed', 'days_carried_over', 'days_taken_before'])->all(),
            'toil' => ToilAccrual::query()->where('person_id', $personId)->get()->map->only(['date', 'days', 'reason'])->all(),
            'timesheets' => Timesheet::query()->with('entries')->where('person_id', $personId)->get()->map(fn (Timesheet $t) => $t->toWorkspaceArray())->all(),
            'expenses' => Expense::query()->where('person_id', $personId)->get()->map->only(['date', 'category', 'description', 'amount_minor', 'currency', 'status'])->all(),
            'freelancer_invoices' => FreelancerInvoice::query()->where('person_id', $personId)->get()->toArray(),
            'travel' => TravelBooking::query()->where('person_id', $personId)->get()->map(fn (TravelBooking $t) => ['event_id' => $t->event_id, 'summary' => $t->summary(), 'reference' => $t->reference, 'status' => $t->status->value])->all(),
            'checklists' => Checklist::query()->with('items')->where('person_id', $personId)->get()->toArray(),
            'notifications' => DB::table('notifications')->where(fn ($q) => $q->where('notifiable_type', Person::class)->where('notifiable_id', $personId)
                ->when($account, fn ($q) => $q->orWhere(fn ($q) => $q->where('notifiable_type', User::class)->where('notifiable_id', $account?->id))))
                ->get(['data', 'created_at', 'read_at'])->map(fn ($n) => ['notice' => json_decode((string) $n->data, true), 'at' => $n->created_at, 'read' => $n->read_at])->all(),
            'ask_hive_questions' => $account ? AiRequest::query()->where('user_id', $account->id)->get(['prompt', 'response', 'created_at'])->toArray() : [],
            'activity_about_them' => Activity::query()->where('subject_type', Person::class)->where('subject_id', $personId)->get(['description', 'event', 'created_at'])->toArray(),
            'activity_by_them' => $account ? Activity::query()->where('causer_type', User::class)->where('causer_id', $account->id)->get(['description', 'event', 'created_at'])->toArray() : [],
        ];

        $path = tempnam(sys_get_temp_dir(), 'sar');

        if ($path === false) {
            throw new RuntimeException('Could not create the export file.');
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('hive-data.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('README.txt', "Everything Hive holds about {$person->first_name} {$person->last_name}, exported on ".now()->format('j M Y').".\nhive-data.json has the records; documents/ has their uploaded files.\n");

        $disk = Storage::disk(config('filesystems.documents_disk', 'local'));

        foreach ($person->documents as $doc) {
            if ($doc->file_path && $disk->exists($doc->file_path)) {
                $zip->addFromString('documents/'.$doc->id.'-'.basename((string) ($doc->original_name ?? $doc->file_path)), (string) $disk->get($doc->file_path));
            }
        }

        $zip->close();

        activity('gdpr')->causedBy($user)->performedOn($person)
            ->withProperties(['ref' => 'P-'.$personId])
            ->event('subject-access-export')->log("Exported everything Hive holds about {$person->first_name} {$person->last_name}");

        return $path;
    }
}
