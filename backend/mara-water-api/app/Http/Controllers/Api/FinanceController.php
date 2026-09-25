<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTrip;
use App\Models\MaterialBatch;
use App\Models\PayrollRun;
use App\Models\PettyCashEntry;
use App\Models\User;
use App\Services\FinanceLedgerService;
use App\Services\SalesRevenueService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Round 5B Phase 5: ledger summary.
 * Ops brief §9: Director live profit = Gross Revenue − COGS − Salaries − Other.
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

    /**
     * Director live profit for a calendar month (YYYY-MM).
     *
     * Formula: Profit = Gross Revenue − COGS − Salaries − Other deductions
     *   - Gross revenue: completed orders + driver trip sales
     *   - COGS: approximate from material batch GRN purchases
     *     (unit_cost * qty_received) with purchase_date in the month —
     *     NOT sold-unit BOM consumption. We use purchase/GRN cost because
     *     that is the purchase ledger we reliably have per month; true
     *     matching COGS would need timed BOM consumption of units sold.
     *   - Salaries: payslip gross_pay for a payroll run in that month if
     *     one exists (prefer finalized); else sum of active operational
     *     staff salaries (exclude access_tier=director)
     *   - Other: petty cash OUT entries + trip fuel_cost for the month.
     *     Repairs/VAT are only included when coded through petty cash;
     *     there is no separate VAT tracker in the ledger today.
     */
    public function profitSummary(Request $request, SalesRevenueService $revenue)
    {
        $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $monthStart = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $from = $monthStart->toDateString();
        $to = $monthEnd->toDateString();

        $ordersRevenue = round($revenue->ordersRevenueBetween($from, $to), 2);
        $tripRevenue = round($revenue->tripRevenueBetween($from, $to), 2);
        $grossRevenue = round($ordersRevenue + $tripRevenue, 2);

        // COGS assumption (documented above): GRN / material_batches purchases.
        $cogs = round((float) MaterialBatch::query()
            ->whereBetween('purchase_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(unit_cost * qty_received), 0) as total')
            ->value('total'), 2);

        $payrollRun = PayrollRun::with('payslips')
            ->whereDate('month', $from)
            ->orderByRaw("CASE WHEN status = 'finalized' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->first();

        $salariesSource = 'staff_salaries';
        $salariesGross = 0.0;
        $salariesNet = 0.0;
        $payrollRunId = null;
        $payrollStatus = null;

        if ($payrollRun) {
            $salariesSource = 'payroll_run';
            $payrollRunId = $payrollRun->id;
            $payrollStatus = $payrollRun->status;
            $salariesGross = round((float) $payrollRun->payslips->sum('gross_pay'), 2);
            $salariesNet = round((float) $payrollRun->payslips->sum('net_salary'), 2);
        } else {
            $salariesGross = round((float) User::where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('access_tier', '!=', 'director'))
                ->sum('salary'), 2);
            $salariesNet = $salariesGross;
        }

        // Profit formula uses gross payroll cost when a run exists; otherwise
        // the operational staff salary snapshot (same figure as gross here).
        $salaries = $salariesGross;

        $pettyCashOut = round((float) PettyCashEntry::whereNull('deleted_at')
            ->whereBetween('entry_date', [$from, $to])
            ->sum('amount_out'), 2);

        $fuelCost = round((float) DriverTrip::whereNull('deleted_at')
            ->whereBetween('trip_date', [$from, $to])
            ->sum('fuel_cost'), 2);

        $other = round($pettyCashOut + $fuelCost, 2);
        $profit = round($grossRevenue - $cogs - $salaries - $other, 2);

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $request->month,
                'period' => ['from' => $from, 'to' => $to],
                'gross_revenue' => $grossRevenue,
                'gross_revenue_breakdown' => [
                    'completed_orders' => $ordersRevenue,
                    'driver_trip_sales' => $tripRevenue,
                ],
                'cogs' => $cogs,
                'cogs_basis' => 'material_batch_purchases_unit_cost_x_qty_received',
                'salaries' => $salaries,
                'salaries_breakdown' => [
                    'source' => $salariesSource,
                    'gross' => $salariesGross,
                    'net' => $salariesNet,
                    'payroll_run_id' => $payrollRunId,
                    'payroll_status' => $payrollStatus,
                ],
                'other_deductions' => $other,
                'other_breakdown' => [
                    'petty_cash_out' => $pettyCashOut,
                    'fuel_cost' => $fuelCost,
                    'vat_tracked' => false,
                    'repairs_note' => 'Included only when coded as petty cash OUT',
                ],
                'profit' => $profit,
                'formula' => 'gross_revenue - cogs - salaries - other_deductions',
            ],
        ]);
    }
}
