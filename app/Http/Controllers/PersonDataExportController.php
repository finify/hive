<?php

namespace App\Http\Controllers;

use App\Actions\Gdpr\ExportPersonData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads a subject access export as a ZIP, deleted from the server once
 * sent.
 */
class PersonDataExportController extends Controller
{
    public function __invoke(Request $request, ExportPersonData $export, int $person): BinaryFileResponse
    {
        $path = $export->handle($request->user(), $person);

        return response()->download($path, "hive-person-{$person}-".now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
