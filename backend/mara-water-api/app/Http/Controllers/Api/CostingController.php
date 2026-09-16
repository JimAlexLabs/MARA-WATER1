<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomItem;
use App\Models\PettyCashEntry;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Costing & P&L (Phase 9) -- from the "KDQ Reopening Plan" model.
 * Per-bottle cost is read straight off the bill of materials (Phase 8)
 * and each material's unit_cost, not re-typed per product. Revenue and
 * COGS are read from real Sales data (Phase 7) for the period, not a
 * rebuilt spreadsheet.
 */
class CostingController extends Controller
{
    /**
     * Per-bottle material cost for every active SKU: sum(qty_per_unit *
     * material.unit_cost) across its bill of materials. A SKU with no
     * BOM lines set up, or materials with unit_cost still at 0 (nobody
     * has priced them yet), correctly costs 0 -- not a guessed number.
     */
    public function perBottleCost()
    {
        $skus = Sku::where('active', true)->orderBy('brand')->orderBy('name')->get();

        $bomBySku = BomItem::with('material')->whereNull('deleted_at')->get()->groupBy('sku_id');

        $rows = $skus->map(function ($sku) use ($bomBySku) {
            $lines = $bomBySku->get($sku->id, collect());
            $cost = $lines->sum(fn ($line) => (float) $line->qty_per_unit * (float) ($line->material->unit_cost ?? 0));

            return [
                'sku_id' => $sku->id,
                'sku' => ['id' => $sku->id, 'code' => $sku->code, 'name' => $sku->name, 'brand' => $sku->brand],
                'material_cost' => round($cost, 4),
                'bom_lines' => $lines->map(fn ($l) => [
                    'material' => $l->material->name ?? 'Material',
                    'qty_per_unit' => (float) $l->qty_per_unit,
                    'unit_cost' => (float) ($l->material->unit_cost ?? 0),
                    'line_cost' => round((float) $l->qty_per_unit * (float) ($l->material->unit_cost ?? 0), 4),
                ])->values(),
                'has_bom' => $lines->isNotEmpty(),
            ];
        });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Trading P&L: revenue and COGS from real sales data for the period
     * (net qty sold * unit price for revenue, net qty sold * per-bottle
     * material cost for COGS), plus current monthly payroll and the
     * period's coded operating expenses from petty cash, laid out the
     * way the "KDQ Reopening Plan" workbook did: revenue - COGS = gross
     * margin, then - payroll - overhead = net margin.
     */
    public function profitAndLoss(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        // Per-bottle material cost, same computation as perBottleCost().
        $bomBySku = BomItem::with('material')->whereNull('deleted_at')->get()->groupBy('sku_id');
        $costBySku = $bomBySku->map(fn ($lines) => $lines->sum(
            fn ($line) => (float) $line->qty_per_unit * (float) ($line->material->unit_cost ?? 0)
        ));

        // Raw DB::table(), not the OrderItem Eloquent builder: selectRaw
        // aliases here would otherwise collide with OrderItem's own
        // net_qty/line_total accessors (Phase 7) -- Eloquent's attribute
        // access always prefers an accessor method over a same-named
        // loaded column, so `$row->net_qty` would silently recompute
        // qty - qty_returned from the real (unselected, therefore null)
        // columns instead of returning this query's aggregate.
        $salesLines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
            ->whereDate('orders.order_date', '>=', $dateFrom)
            ->whereDate('orders.order_date', '<=', $dateTo)
            ->selectRaw('order_items.sku_id, SUM(order_items.qty - order_items.qty_returned) as total_net_qty, SUM((order_items.qty - order_items.qty_returned) * order_items.unit_price) as total_revenue')
            ->groupBy('order_items.sku_id')
            ->get();

        $revenue = 0.0;
        $cogs = 0.0;
        $bySku = $salesLines->map(function ($row) use (&$revenue, &$cogs, $costBySku) {
            $unitCost = (float) ($costBySku->get($row->sku_id) ?? 0);
            $lineCogs = $unitCost * (float) $row->total_net_qty;
            $revenue += (float) $row->total_revenue;
            $cogs += $lineCogs;

            return [
                'sku_id' => $row->sku_id,
                'net_qty_sold' => (int) $row->total_net_qty,
                'revenue' => round((float) $row->total_revenue, 2),
                'unit_cost' => round($unitCost, 4),
                'cogs' => round($lineCogs, 2),
                'gross_margin' => round((float) $row->total_revenue - $lineCogs, 2),
            ];
        });

        $grossMargin = $revenue - $cogs;

        // Current monthly payroll (active staff) -- a snapshot, not
        // prorated to the report period, since salary isn't dated.
        $monthlyPayroll = (float) User::where('status', 'active')->sum('salary');

        // Operating expenses actually coded through petty cash in the
        // period, split out from salaries (paid separately, above) so
        // they aren't double-counted if any salary-coded entries exist.
        $overhead = (float) PettyCashEntry::whereNull('deleted_at')
            ->whereBetween('entry_date', [$dateFrom, $dateTo])
            ->whereHas('account', fn ($q) => $q->where('category', 'expense')->where('code', '!=', '5000'))
            ->sum('amount_out');

        $netMargin = $grossMargin - $monthlyPayroll - $overhead;

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from' => $dateFrom, 'to' => $dateTo],
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'gross_margin' => round($grossMargin, 2),
                'gross_margin_pct' => $revenue > 0 ? round($grossMargin / $revenue * 100, 2) : null,
                'monthly_payroll' => round($monthlyPayroll, 2),
                'overhead_expenses' => round($overhead, 2),
                'net_margin' => round($netMargin, 2),
                'by_sku' => $bySku->values(),
            ],
        ]);
    }
}
