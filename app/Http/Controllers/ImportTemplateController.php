<?php

namespace App\Http\Controllers;

use App\Actions\Integrations\ImportCsv;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV with the header row the importer reads, and one example row.
 */
class ImportTemplateController extends Controller
{
    private const EXAMPLES = [
        'people' => ['Sam', 'Example', 'sam.example@lumenco.test', 'employee', 'Production Coordinator', 'London', 'United Kingdom', '2027-01-04', 'Production', '', '', '', '', ''],
        'clients' => ['Example Group plc', 'Financial services', '30', '1 Example Street, London', 'GB123456789'],
        'suppliers' => ['Example Staging Ltd', 'staging', '30', 'GB987654321', '4.2', '2027-06-30'],
        'venues' => ['Example Hall', '1 Example Road', 'Manchester', 'United Kingdom', '800', 'Loading bay on the north side', '63A three-phase'],
    ];

    public function __invoke(Request $request, string $type): StreamedResponse
    {
        abort_unless(isset(ImportCsv::COLUMNS[$type]), 404);
        abort_unless($request->user()?->can(ImportCsv::permission($type)), 403);

        return Csv::download("hive-{$type}-template.csv", [ImportCsv::COLUMNS[$type], self::EXAMPLES[$type]]);
    }
}
