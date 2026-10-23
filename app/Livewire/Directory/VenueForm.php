<?php

namespace App\Livewire\Directory;

use App\Actions\Directory\SaveVenue;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class VenueForm extends Component
{
    public ?int $venueId = null;

    /** @var array<string, mixed> */
    public array $form = ['name' => '', 'address' => '', 'city' => '', 'country' => 'United Kingdom', 'capacity' => '', 'access_notes' => '', 'power_notes' => ''];

    public function mount(?Venue $venue = null): void
    {
        if ($venue?->exists) {
            $this->authorize('update', $venue);
            $this->venueId = $venue->id;
            $this->form = $venue->only(array_keys($this->form));

            return;
        }

        $this->authorize('create', Venue::class);
    }

    public function save(SaveVenue $save): void
    {
        $this->resetValidation();
        $input = $this->form;
        $input['capacity'] = $input['capacity'] === '' ? null : $input['capacity'];

        try {
            $venue = $save->handle(auth()->user(), $input, $this->venueId ? Venue::query()->findOrFail($this->venueId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['form.'.$k => $m])->all());

            return;
        }

        session()->flash('toast', ['message' => "Saved {$venue->name}."]);
        $this->redirectRoute('venues', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.directory.venue-form')->title($this->venueId ? 'Edit venue' : 'Add a venue');
    }
}
