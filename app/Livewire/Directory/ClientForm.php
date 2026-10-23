<?php

namespace App\Livewire\Directory;

use App\Actions\Directory\SaveClient;
use App\Livewire\Directory\Concerns\EditsContacts;
use App\Models\Client;
use App\Models\Person;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ClientForm extends Component
{
    use EditsContacts;

    public ?int $clientId = null;

    /** @var array<string, mixed> */
    public array $form = ['name' => '', 'sector' => '', 'billing_address' => '', 'vat_number' => '', 'payment_terms_days' => 30, 'account_lead_id' => '', 'notes' => ''];

    public function mount(?Client $client = null): void
    {
        if ($client?->exists) {
            $this->authorize('update', $client);
            $this->clientId = $client->id;
            $this->form = $client->only(array_keys($this->form));
            $this->loadContacts($client->contacts()->get());

            return;
        }

        $this->authorize('create', Client::class);
        $this->addContact();
    }

    public function save(SaveClient $save): void
    {
        $this->resetValidation();
        $input = $this->form + ['contacts' => $this->contacts];
        $input['account_lead_id'] = $input['account_lead_id'] === '' ? null : $input['account_lead_id'];

        try {
            $client = $save->handle(auth()->user(), $input, $this->clientId ? Client::query()->findOrFail($this->clientId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($this->prefixErrors($e->errors()));

            return;
        }

        session()->flash('toast', ['message' => "Saved {$client->name}."]);
        $this->redirectRoute('clients', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.directory.client-form', [
            'leads' => Person::query()->active()->where('employment_type', '!=', 'freelancer')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'preferred_name']),
        ])->title($this->clientId ? 'Edit client' : 'Add a client');
    }
}
