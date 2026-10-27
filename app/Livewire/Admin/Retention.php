<?php

namespace App\Livewire\Admin;

use App\Actions\Gdpr\DecideRetention;
use App\Livewire\Concerns\UsesWorkspace;
use App\Models\RetentionFlag;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Data retention')]
class Retention extends Component
{
    use UsesWorkspace;

    public function decide(int $flagId, bool $remove, DecideRetention $decide): void
    {
        try {
            $flag = $decide->handle(auth()->user(), $flagId, $remove);
        } catch (ValidationException $e) {
            $this->toast((string) collect($e->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(match ($flag->decision) {
            'keep' => 'Kept.',
            'anonymised' => 'Anonymised; their financial records stay.',
            default => 'Deleted.',
        });
    }

    public function render(): View
    {
        return view('livewire.admin.retention', [
            'open' => RetentionFlag::query()->whereNull('decision')->orderBy('past_retention_on')->get(),
            'decided' => RetentionFlag::query()->whereNotNull('decision')->latest('decided_at')->limit(20)->get(),
            'retention' => config('hive.retention'),
        ]);
    }
}
