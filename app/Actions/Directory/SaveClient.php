<?php

namespace App\Actions\Directory;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveClient
{
    public function __construct(private readonly SaveContacts $contacts) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?Client $client = null): Client
    {
        $client === null
            ? Gate::forUser($user)->authorize('create', Client::class)
            : Gate::forUser($user)->authorize('update', $client);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150', Rule::unique('clients', 'name')->ignore($client?->id)],
            'sector' => ['required', 'string', 'max:80'],
            'billing_address' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:20'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:120'],
            'account_lead_id' => ['nullable', 'integer', 'exists:people,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + SaveContacts::rules())->validate();

        return DB::transaction(function () use ($client, $data) {
            $client ??= new Client;
            $client->fill(collect($data)->except('contacts')->all())->save();
            $this->contacts->handle($client, $data['contacts'] ?? []);

            return $client->refresh();
        });
    }
}
