<?php

namespace App\Livewire\Directory\Concerns;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

trait EditsContacts
{
    /** @var list<array{name: string, role: string|null, email: string|null, phone: string|null, is_primary: bool}> */
    public array $contacts = [];

    /**
     * @param  Collection<int, Contact>  $contacts
     */
    protected function loadContacts(Collection $contacts): void
    {
        $this->contacts = $contacts->map(fn (Contact $c) => [
            'name' => $c->name, 'role' => $c->role, 'email' => $c->email, 'phone' => $c->phone, 'is_primary' => $c->is_primary,
        ])->values()->all();
    }

    /**
     * Ticking one primary contact unticks the others.
     */
    public function updatedContacts(mixed $value, string $key): void
    {
        if (str_ends_with($key, '.is_primary') && $value) {
            $chosen = (int) explode('.', $key)[0];

            foreach ($this->contacts as $i => $contact) {
                $this->contacts[$i]['is_primary'] = $i === $chosen;
            }
        }
    }

    public function addContact(): void
    {
        $this->contacts[] = ['name' => '', 'role' => '', 'email' => '', 'phone' => '', 'is_primary' => $this->contacts === []];
    }

    public function removeContact(int $index): void
    {
        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    protected function prefixErrors(array $errors): array
    {
        $out = [];

        foreach ($errors as $key => $messages) {
            $out[str_starts_with($key, 'contacts.') ? $key : 'form.'.$key] = $messages;
        }

        return $out;
    }
}
