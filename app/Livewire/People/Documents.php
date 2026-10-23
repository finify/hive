<?php

namespace App\Livewire\People;

use App\Enums\DocumentStatus;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Documents')]
class Documents extends Component
{
    use UsesWorkspace;

    #[Url(except: 'attention')]
    public string $show = 'attention';

    public function render(): View
    {
        $workspace = $this->workspace();

        $documents = $workspace->documents()
            ->map(fn ($d) => $d + ['status' => $workspace->documentStatus($d)])
            ->when($this->show === 'attention', fn ($c) => $c->filter(fn ($d) => $d['status'] !== DocumentStatus::Valid))
            ->sortBy(fn ($d) => $d['expires_at'] ?? '9999');

        return view('livewire.people.documents', [
            'documents' => $documents,
            'detail' => auth()->user()->can('documents.view'),
        ]);
    }
}
