<?php

namespace App\Actions\Directory;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Supplier;

/**
 * Replaces an organisation's contacts with the list given, keeping exactly
 * one primary contact when there are any.
 */
class SaveContacts
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'contacts' => ['array', 'max:10'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.role' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.is_primary' => ['boolean'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    public function handle(Client|Supplier $owner, array $contacts): void
    {
        $owner->contacts()->delete();
        $contacts = array_values($contacts);
        $primary = collect($contacts)->search(fn ($c) => ! empty($c['is_primary']));
        $primary = $primary === false ? 0 : $primary;

        foreach ($contacts as $i => $contact) {
            $owner->contacts()->save(new Contact([
                'name' => $contact['name'],
                'role' => $contact['role'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'is_primary' => $i === $primary,
            ]));
        }
    }
}
