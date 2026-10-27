<?php

namespace App\Http\Controllers;

use App\Reports\Reports;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report as CSV with the filters on the query string. Every
 * export is logged.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $key): StreamedResponse
    {
        $report = Reports::find($request->user(), $key) ?? abort(403);
        $filters = $report->resolve(array_map('strval', array_filter($request->query(), 'is_scalar')));
        $rows = $report->csv($filters);

        activity('reports')
            ->causedBy($request->user())
            ->withProperties(['ref' => $key, 'attributes' => ['filters' => $filters, 'rows' => count($rows) - 1]])
            ->event('report-exported')
            ->log("Exported {$report->title()}");

        return Csv::download($report->filename($filters), $rows);
    }
}
