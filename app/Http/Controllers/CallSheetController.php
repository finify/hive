<?php

namespace App\Http\Controllers;

use App\Actions\Resourcing\CallSheet;
use App\Demo\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Downloads an event's call sheet for a day as a PDF.
 */
class CallSheetController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, CallSheet $sheets, string $code, string $date): Response
    {
        $event = $workspace->eventByCode($code);
        abort_unless($event !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        abort_unless(in_array($date, $sheets->days($event['id']), true), 404);
        abort_unless($sheets->canView($request->user(), $event, $date), 403);

        return response($sheets->pdf($event['id'], $date), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$sheets->filename($event['id'], $date).'"',
        ]);
    }
}
