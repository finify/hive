<?php

namespace App\Actions\Directory;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveVenue
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, array $input, ?Venue $venue = null): Venue
    {
        $venue === null
            ? Gate::forUser($user)->authorize('create', Venue::class)
            : Gate::forUser($user)->authorize('update', $venue);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150', Rule::unique('venues', 'name')->ignore($venue?->id)],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'country' => ['required', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'power_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $venue ??= new Venue;
        $venue->fill($data)->save();

        return $venue;
    }
}
