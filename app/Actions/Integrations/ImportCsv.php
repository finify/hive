<?php

namespace App\Actions\Integrations;

use App\Actions\Directory\SaveClient;
use App\Actions\Directory\SaveSupplier;
use App\Actions\Directory\SaveVenue;
use App\Actions\People\SavePerson;
use App\Models\Department;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CSV import of people, clients, suppliers and venues (section 5.13).
 * Every row goes through the same Action as the add form, so the rules are
 * the same. The preview runs the whole file inside a transaction that is
 * rolled back, which also catches duplicates within the file; the import
 * only runs when every row passes, and is all or nothing.
 */
class ImportCsv
{
    public const MAX_ROWS = 500;

    /** Columns per type: the first ones are required by the add form. */
    public const COLUMNS = [
        'people' => ['first_name', 'last_name', 'email', 'employment_type', 'job_title', 'location_city', 'location_country', 'start_date', 'department', 'line_manager_email', 'preferred_name', 'phone', 'end_date', 'company_name'],
        'clients' => ['name', 'sector', 'payment_terms_days', 'billing_address', 'vat_number'],
        'suppliers' => ['name', 'category', 'payment_terms_days', 'vat_number', 'rating', 'insurance_expires_at'],
        'venues' => ['name', 'address', 'city', 'country', 'capacity', 'access_notes', 'power_notes'],
    ];

    public function __construct(
        private readonly SavePerson $people,
        private readonly SaveClient $clients,
        private readonly SaveSupplier $suppliers,
        private readonly SaveVenue $venues,
    ) {}

    public static function permission(string $type): string
    {
        return $type === 'people' ? 'people.manage' : 'directory.manage';
    }

    /**
     * Reads a CSV with a header row into rows keyed by column name.
     *
     * @return list<array<string, string>>
     */
    public function parse(string $type, string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', ltrim($csv, "\xEF\xBB\xBF")) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $header = array_map(fn ($h) => str_replace([' ', '-'], '_', strtolower(trim((string) $h))), str_getcsv(array_shift($lines), escape: '\\'));
        $unknown = array_diff(array_filter($header), self::COLUMNS[$type]);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['file' => 'Unknown columns: '.implode(', ', $unknown).'. Download the template for the columns Hive reads.']);
        }

        if (count($lines) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Import up to '.self::MAX_ROWS.' rows at a time.']);
        }

        return array_map(function (string $line) use ($header) {
            $cells = str_getcsv($line, escape: '\\');
            $row = [];

            foreach ($header as $i => $column) {
                if ($column !== '') {
                    $row[$column] = trim((string) ($cells[$i] ?? ''));
                }
            }

            return $row;
        }, $lines);
    }

    /**
     * Checks every row without saving anything.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, row: array<string, string>, errors: list<string>}>
     */
    public function preview(User $user, string $type, array $rows): array
    {
        Gate::forUser($user)->authorize(self::permission($type));
        $result = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $i => $row) {
                $result[] = ['line' => $i + 2, 'row' => $row, 'errors' => $this->attempt($user, $type, $row)];
            }
        } finally {
            DB::rollBack();
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    public function import(User $user, string $type, array $rows): int
    {
        $checked = $this->preview($user, $type, $rows);
        $failed = array_filter($checked, fn ($r) => $r['errors'] !== []);

        if ($failed !== []) {
            throw ValidationException::withMessages(['file' => count($failed).' rows have problems. Fix them and upload the file again.']);
        }

        DB::transaction(function () use ($user, $type, $rows) {
            foreach ($rows as $row) {
                $this->save($user, $type, $row);
            }
        });

        activity('integrations')->causedBy($user)
            ->withProperties(['ref' => 'import-'.$type, 'attributes' => ['type' => $type, 'rows' => count($rows)]])
            ->event('csv-imported')->log('Imported '.count($rows).' '.($type === 'people' && count($rows) === 1 ? 'person' : $type).' from CSV');

        return count($rows);
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function attempt(User $user, string $type, array $row): array
    {
        if ($type === 'people') {
            $lookups = array_filter([
                ($row['department'] ?? '') !== '' && ! is_int($this->department($row['department'])) ? "There is no department called {$row['department']}." : null,
                ($row['line_manager_email'] ?? '') !== '' && ! is_int($this->manager($row['line_manager_email'])) ? "Nobody in Hive has the email {$row['line_manager_email']}." : null,
            ]);

            if ($lookups !== []) {
                return array_values($lookups);
            }
        }

        try {
            $this->save($user, $type, $row);
        } catch (ValidationException $e) {
            return array_values(array_map('strval', collect($e->errors())->flatten()->all()));
        } catch (Throwable $e) {
            report($e);

            return ['This row could not be read.'];
        }

        return [];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function save(User $user, string $type, array $row): void
    {
        $value = fn (string $key): ?string => ($row[$key] ?? '') === '' ? null : $row[$key];

        match ($type) {
            'people' => $this->people->handle($user, [
                'first_name' => $value('first_name'), 'last_name' => $value('last_name'), 'preferred_name' => $value('preferred_name'),
                'email' => $value('email'), 'phone' => $value('phone'),
                'employment_type' => $value('employment_type') !== null ? str_replace(' ', '-', strtolower((string) $value('employment_type'))) : null,
                'department_id' => $this->department($value('department')),
                'line_manager_id' => $this->manager($value('line_manager_email')),
                'job_title' => $value('job_title'), 'location_city' => $value('location_city'), 'location_country' => $value('location_country'),
                'start_date' => $value('start_date'), 'end_date' => $value('end_date'), 'company_name' => $value('company_name'),
                'vat_registered' => false, 'skills' => [], 'restricted' => [], 'rate' => [],
            ]),
            'clients' => $this->clients->handle($user, [
                'name' => $value('name'), 'sector' => $value('sector'), 'payment_terms_days' => $value('payment_terms_days'),
                'billing_address' => $value('billing_address'), 'vat_number' => $value('vat_number'), 'contacts' => [],
            ]),
            'suppliers' => $this->suppliers->handle($user, [
                'name' => $value('name'), 'category' => $value('category') !== null ? str_replace(' ', '-', strtolower((string) $value('category'))) : null,
                'payment_terms_days' => $value('payment_terms_days'), 'vat_number' => $value('vat_number'), 'rating' => $value('rating'),
                'insurance_expires_at' => $value('insurance_expires_at'), 'is_approved' => false, 'contacts' => [],
            ]),
            'venues' => $this->venues->handle($user, [
                'name' => $value('name'), 'address' => $value('address'), 'city' => $value('city'), 'country' => $value('country'),
                'capacity' => $value('capacity'), 'access_notes' => $value('access_notes'), 'power_notes' => $value('power_notes'),
            ]),
            default => throw ValidationException::withMessages(['file' => 'Unknown import type.']),
        };
    }

    private function department(?string $name): ?int
    {
        $id = $name === null ? null : Department::query()->whereRaw('lower(name) = ?', [strtolower($name)])->value('id');

        return $id === null ? null : (int) $id;
    }

    private function manager(?string $email): ?int
    {
        // Their work email on the person record, or the email they sign in with.
        $id = $email === null ? null : (Person::query()->where('email', strtolower($email))->value('id')
            ?? User::query()->where('email', strtolower($email))->value('person_id'));

        return $id === null ? null : (int) $id;
    }
}
