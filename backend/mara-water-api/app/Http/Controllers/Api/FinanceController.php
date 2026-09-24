<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceLedgerService;
use Illuminate\Http\Request;

/**
 * Round 5B Phase 5: thin controller for the shared ledger summary.
 * Manager dashboards (Claude Code) and Director Finance/Reports should
 * both call this so figures never diverge by role.
 */
class FinanceController extends Controller
{
    public function ledgerSummary(Request $request, FinanceLedgerService $ledger)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        return response()->json([
            'success' => true,
            'data' => $ledger->summary($dateFrom, $dateTo),
        ]);
    }
}
