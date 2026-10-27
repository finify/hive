<?php

namespace App\Http\Controllers;

use App\Actions\Travel\TravelManifest;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an event's travel manifest as CSV.
 */
class TravelManifestController extends Controller
{
    public function __invoke(Request $request, TravelManifest $manifest, string $code): StreamedResponse
    {
        $result = $manifest->build($request->user(), $code);

        return Csv::download($result['filename'], $result['rows']);
    }
}
