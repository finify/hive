<?php

namespace App\Http\Controllers;

use App\Actions\Finance\ExportFinance;
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

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            foreach ($result['rows'] as $row) {
                // Guard against formula injection when the file is opened in a spreadsheet.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) === 1 && ! is_numeric($v) ? "'".$v : $v, $row), escape: '\\');
            }

            fclose($out);
        }, $result['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
