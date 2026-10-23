<?php

namespace App\Actions\Events;

use App\Actions\People\UploadDocument;
use App\Demo\Workspace;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Stores a brief, floor plan, run of show or the signed client contract
 * privately against an event. A contract is what lets the event be
 * confirmed (rule 1).
 */
class UploadEventFile
{
    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, Event $event, array $input): Document
    {
        Gate::forUser($user)->authorize('event.edit', [$this->workspace->event($event->id) ?? []]);

        $data = Validator::make($input, [
            'type' => ['required', Rule::in(array_keys(DocumentType::eventOptions()))],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:'.UploadDocument::MAX_KILOBYTES],
        ], [
            'file.required' => 'Choose the file to upload.',
            'file.mimes' => 'Upload a PDF, JPG, PNG or Word document.',
            'file.max' => 'Files can be up to 10 MB.',
        ])->validate();

        /** @var UploadedFile $file */
        $file = $data['file'];
        $type = DocumentType::from($data['type']);
        $hadContract = $event->documents()->where('type', DocumentType::Contract)->exists();

        $document = $event->documents()->create([
            'type' => $type,
            'title' => DocumentType::eventOptions()[$type->value],
            'file_path' => $file->store("documents/events/{$event->id}", config('filesystems.documents_disk', 'local')),
            'original_name' => $file->getClientOriginalName(),
            'mandatory' => false,
            'uploaded_by' => $user->id,
        ]);

        $contract = $type === DocumentType::Contract;

        activity('events')
            ->causedBy($user)
            ->performedOn($event)
            ->withProperties([
                'ref' => $event->code,
                'old' => $contract ? ['contract_signed' => $hadContract] : [],
                'attributes' => ['type' => $type->value, 'file' => $document->original_name] + ($contract ? ['contract_signed' => true] : []),
            ])
            ->event($contract ? 'contract-signed' : 'file-uploaded')
            ->log(($contract ? 'Uploaded the signed contract for ' : 'Uploaded the '.mb_strtolower($type->label()).' for ').$event->name);

        $this->workspace->forget();

        return $document;
    }
}
