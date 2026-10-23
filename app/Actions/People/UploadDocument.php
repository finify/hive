<?php

namespace App\Actions\People;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Stores a document privately (never on the public disk) against a person.
 * Files are opened later through a short-lived signed link.
 */
class UploadDocument
{
    public const MAX_KILOBYTES = 10_240;

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $user, Person $person, array $input): Document
    {
        Gate::forUser($user)->authorize('uploadDocument', $person);

        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(DocumentType::class)],
            'title' => ['required', 'string', 'max:150'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:'.self::MAX_KILOBYTES],
        ], [
            'file.required' => 'Choose the file to upload.',
            'file.mimes' => 'Upload a PDF, JPG, PNG or Word document.',
            'file.max' => 'Files can be up to 10 MB.',
        ])->validate();

        /** @var UploadedFile $file */
        $file = $data['file'];
        $type = DocumentType::from($data['type']);
        $path = $file->store("documents/people/{$person->id}", config('filesystems.documents_disk', 'local'));

        $document = $person->documents()->create([
            'type' => $type,
            'title' => $data['title'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'mandatory' => $person->employment_type->isFreelance() && $type->isMandatory(),
            'uploaded_by' => $user->id,
        ]);

        activity('people')
            ->causedBy($user)
            ->performedOn($document)
            ->withProperties(['ref' => 'P-'.$person->id, 'attributes' => ['type' => $type->value, 'expires_at' => $data['expires_at'] ?? null]])
            ->event('document-uploaded')
            ->log("Uploaded {$data['title']} for {$person->displayName()}");

        return $document;
    }
}
