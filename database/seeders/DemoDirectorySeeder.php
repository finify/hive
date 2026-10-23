<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Demo\Rows;
use App\Enums\DocumentType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Document;
use App\Models\Person;
use App\Models\Skill;
use App\Models\Supplier;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Facades\CauserResolver;
use Spatie\Activitylog\Models\Activity;

/**
 * Loads the fictional agency's people and directory from database/demo/*.json
 * into the database, keeping the JSON ids so events, bookings and timesheets
 * (still JSON until their phase) keep pointing at the right records.
 */
class DemoDirectorySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local, staging and test environments only.');
        }

        $data = app(DemoData::class);
        CauserResolver::setCauser(null);

        DB::transaction(function () use ($data) {
            activity()->disableLogging();

            foreach (Rows::of($data->raw('skills')) as $row) {
                Skill::query()->forceCreate(['id' => $row['id'], 'name' => $row['name'], 'category' => $row['category']]);
            }

            $organisation = $data->raw('organisation');

            foreach (Rows::of($organisation['departments']) as $row) {
                Department::query()->forceCreate(['id' => $row['id'], 'name' => $row['name']]);
            }

            $people = Rows::of($data->raw('people'));

            foreach ($people as $row) {
                $person = Person::query()->forceCreate([
                    'id' => $row['id'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'],
                    'preferred_name' => $row['preferred_name'], 'pronouns' => $row['pronouns'], 'email' => $row['email'],
                    'phone' => $row['phone'], 'employment_type' => $row['employment_type'], 'department_id' => $row['department_id'],
                    'job_title' => $row['job_title'], 'location_city' => $row['location_city'], 'location_country' => $row['location_country'],
                    'start_date' => $row['start_date'], 'end_date' => $row['end_date'], 'company_name' => $row['company_name'],
                    'vat_registered' => $row['vat_registered'], 'vat_number' => $row['vat_number'], 'payment_terms_days' => $row['payment_terms_days'],
                    'languages' => $row['languages'], 'rating' => $row['rating'], 'notes' => $row['notes'], 'is_active' => $row['is_active'],
                ]);

                $restricted = is_array($row['restricted']) ? $row['restricted'] : [];
                $person->restricted()->create($restricted);

                foreach (Rows::of($row['day_rates']) as $rate) {
                    $person->dayRates()->create($rate);
                }

                foreach (Rows::of($row['skills']) as $skill) {
                    $person->skills()->attach($skill['skill_id'], [
                        'level' => $skill['level'],
                        'verified_at' => $skill['verified'] ? '2026-01-15 09:00:00' : null,
                    ]);
                }
            }

            // Managers and department heads point at people, so set them once everyone exists.
            foreach ($people as $row) {
                if ($row['line_manager_id']) {
                    Person::query()->whereKey($row['id'])->update(['line_manager_id' => $row['line_manager_id']]);
                }
            }

            foreach (Rows::of($organisation['departments']) as $row) {
                Department::query()->whereKey($row['id'])->update(['head_person_id' => $row['head_person_id']]);
            }

            foreach (Rows::of($data->raw('documents')) as $row) {
                Document::query()->forceCreate([
                    'id' => $row['id'], 'documentable_type' => Person::class, 'documentable_id' => $row['person_id'],
                    'type' => $row['type'], 'title' => $row['title'], 'issued_at' => $row['issued_at'],
                    'expires_at' => $row['expires_at'], 'mandatory' => $row['mandatory'],
                ]);
            }

            foreach (Rows::of($data->raw('clients')) as $row) {
                $client = Client::query()->forceCreate([
                    'id' => $row['id'], 'name' => $row['name'], 'sector' => $row['sector'], 'billing_address' => $row['billing_address'],
                    'vat_number' => $row['vat_number'], 'payment_terms_days' => $row['payment_terms_days'], 'account_lead_id' => $row['account_lead_id'],
                ]);
                $this->contacts($client, $row['contacts']);
            }

            foreach (Rows::of($data->raw('suppliers')) as $row) {
                $supplier = Supplier::query()->forceCreate([
                    'id' => $row['id'], 'name' => $row['name'], 'category' => $row['category'], 'vat_number' => $row['vat_number'],
                    'payment_terms_days' => $row['payment_terms_days'], 'is_approved' => $row['is_approved'], 'rating' => $row['rating'],
                ]);
                $this->contacts($supplier, $row['contacts']);
                $supplier->documents()->create([
                    'type' => DocumentType::PublicLiabilityInsurance, 'title' => 'Public liability insurance',
                    'expires_at' => $row['insurance_expires_at'], 'mandatory' => true,
                ]);
            }

            foreach (Rows::of($data->raw('venues')) as $row) {
                Venue::query()->forceCreate($row);
            }

            activity()->enableLogging();
        });

        Activity::query()->where('log_name', 'people')->orWhere('log_name', 'directory')->delete();
    }

    private function contacts(Client|Supplier $owner, mixed $contacts): void
    {
        foreach (Rows::of($contacts) as $contact) {
            $owner->contacts()->save(new Contact($contact));
        }
    }
}
