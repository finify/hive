<?php

namespace App\Livewire\Admin;

use App\Actions\Integrations\ImportCsv;
use App\Livewire\Concerns\UsesWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Upload a CSV, see every row checked against the same rules as the add
 * form, then import the lot.
 */
#[Layout('layouts.app')]
#[Title('Import from CSV')]
class Import extends Component
{
    use UsesWorkspace;
    use WithFileUploads;

    public string $type = 'people';

    /** @var TemporaryUploadedFile|null */
    public $file;

    /** @var list<array<string, string>> */
    public array $rows = [];

    /** @var list<array{line: int, row: array<string, string>, errors: list<string>}> */
    public array $checked = [];

    public function updatedType(): void
    {
        $this->reset('file', 'rows', 'checked');
        $this->resetValidation();
    }

    public function check(ImportCsv $import): void
    {
        $this->resetValidation();
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], ['file.required' => 'Choose a CSV file.', 'file.mimes' => 'Upload a .csv file.']);
        abort_unless(auth()->user()->can(ImportCsv::permission($this->type)), 403);

        try {
            $this->rows = $import->parse($this->type, (string) $this->file->get());
            $this->checked = $import->preview(auth()->user(), $this->type, $this->rows);
        } catch (ValidationException $e) {
            $this->addError('file', (string) collect($e->errors())->flatten()->first());
            $this->rows = $this->checked = [];
        }
    }

    public function import(ImportCsv $import): void
    {
        try {
            $count = $import->import(auth()->user(), $this->type, $this->rows);
        } catch (ValidationException $e) {
            $this->addError('file', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('file', 'rows', 'checked');
        $this->toast("Imported {$count} ".($count === 1 ? 'row' : 'rows').'.');
    }

    public function render(): View
    {
        $user = auth()->user();
        $types = array_filter(['people' => 'People', 'clients' => 'Clients', 'suppliers' => 'Suppliers', 'venues' => 'Venues'],
            fn ($label, $type) => $user->can(ImportCsv::permission($type)), ARRAY_FILTER_USE_BOTH);

        if (! isset($types[$this->type])) {
            $this->type = (string) array_key_first($types);
        }

        return view('livewire.admin.import', [
            'types' => $types,
            'columns' => ImportCsv::COLUMNS[$this->type] ?? [],
            'problems' => count(array_filter($this->checked, fn ($r) => $r['errors'] !== [])),
        ]);
    }
}
