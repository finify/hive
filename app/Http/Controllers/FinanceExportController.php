<?php

namespace App\Http\Controllers;

use App\Actions\Finance\ExportFinance;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a finance export as CSV.
 */
class FinanceExportController extends Controller
{
    public function __invoke(Request $request, ExportFinance $export, string $type): StreamedResponse
    {
        $result = $export->build($request->user(), $type, (string) $request->query('from'), (string) $request->query('to'));

        return Csv::download($result['filename'], $result['rows']);
    }
}
