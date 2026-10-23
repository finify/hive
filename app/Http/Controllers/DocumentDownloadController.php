<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves private documents. Links are signed and expire after five minutes,
 * and the policy is checked again when the link is opened.
 */
class DocumentDownloadController extends Controller
{
    public static function link(Document $document): string
    {
        return URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]);
    }

    public function __invoke(Request $request, Document $document): StreamedResponse
    {
        Gate::authorize('download', $document);
        abort_if($document->file_path === null, 404);

        $disk = Storage::disk(config('filesystems.documents_disk', 'local'));
        abort_unless($disk->exists($document->file_path), 404);

        activity('people')
            ->causedBy($request->user())
            ->performedOn($document)
            ->withProperties(['ref' => 'D-'.$document->id])
            ->event('document-opened')
            ->log("Opened {$document->title}");

        return $disk->download($document->file_path, $document->original_name ?? basename($document->file_path));
    }
}
