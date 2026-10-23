<?php

namespace App\Http\Controllers;

use App\Actions\Finance\ManagePurchaseOrder;
use App\Demo\Viewer;
use App\Demo\Workspace;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A purchase order as a PDF, for anyone who can see the event's budget.
 */
class PurchaseOrderPdfController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, ManagePurchaseOrder $manage, string $code): Response
    {
        $po = PurchaseOrder::query()->where('code', $code)->firstOrFail();
        $event = $workspace->event($po->event_id);
        abort_unless($event !== null && $request->user()->can('po.view') && (new Viewer($request->user(), $workspace))->canSeeBudget($event), 403);

        return response($manage->pdf($po), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$po->code.'.pdf"']);
    }
}
