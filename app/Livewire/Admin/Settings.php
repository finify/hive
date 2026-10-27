<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\UsesWorkspace;
use App\Support\Settings as HiveSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Limits, thresholds, leave, Ask Hive and retention (section 5.15). Anyone
 * with settings.view can read them; settings.manage changes them.
 */
#[Layout('layouts.app')]
#[Title('Settings')]
class Settings extends Component
{
    use UsesWorkspace;

    /** @var array<string, string|bool> */
    public array $values = [];

    public function mount(): void
    {
        $this->values = HiveSettings::formValues();
    }

    public function save(): void
    {
        $this->resetValidation();

        try {
            $changed = HiveSettings::save(auth()->user(), $this->values);
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['values.'.$k => $m])->all());

            return;
        }

        $this->values = HiveSettings::formValues();
        $this->toast($changed ? "Saved {$changed} ".($changed === 1 ? 'change' : 'changes').'.' : 'Nothing had changed.');
    }

    public function render(): View
    {
        return view('livewire.admin.settings', ['groups' => HiveSettings::FIELDS, 'canManage' => auth()->user()->can('settings.manage')]);
    }
}
