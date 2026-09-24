<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTripItem;
use App\Models\DriverTripSaleItem;
use App\Models\MaterialBatch;
use App\Models\Order;
use App\Models\PackagingRun;
use App\Models\Sku;
use App\Models\SkuPackageConversion;
use App\Models\StockItem;
use App\Models\User;
use App\Services\OperationsExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Director (and Investor high-level) supply-chain Operations Overview:
 * people → Nairobi/Kisumu sourcing → bag inventory → production (bags→bales)
 * → warehouse → dispatch → sales/refills → returns → reports.
 */
class OperationsOverviewController extends Controller
{
    public function overview(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->buildOverviewPayload(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load operations overview',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function buildOverviewPayload(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $trendStart = now()->subDays(29)->toDateString();
        $cfg = config('mara_operations');

        $salaryBands = collect($cfg['salary_bands'] ?? []);
        $payrollBands = $salaryBands->where('include_in_payroll_export', true);
        $targetMonthlyPayroll = $payrollBands->sum(fn ($b) =>
            ((float) $b['default_kes']) * ((int) $b['headcount_target'])
        );

        // Live staff with salaries (exclude director tier from operational payroll totals)
        $staff = User::with('role:id,name,code,access_tier')
            ->where('status', 'active')
            ->whereNotNull('salary')
            ->get();

        $operationalStaff = $staff->filter(fn ($u) => optional($u->role)->access_tier !== 'director');
        $actualMonthlyPayroll = round((float) $operationalStaff->sum('salary'), 2);

        $staffByRole = $operationalStaff->groupBy(fn ($u) => optional($u->role)->name ?: 'Unassigned')
            ->map(fn ($g) => [
                'count' => $g->count(),
                'total_salary' => round((float) $g->sum('salary'), 2),
                'avg_salary' => round((float) $g->avg('salary'), 2),
            ]);

        // Bag inventory (raw bottles) — qty still on material_batches with package_unit bag
        $bagBatches = MaterialBatch::query()
            ->when(Schema::hasColumn('material_batches', 'package_unit'), fn ($q) => $q->where('package_unit', 'bag'))
            ->with('material:id,name,uom')
            ->orderByDesc('purchase_date')
            ->limit(50)
            ->get();

        $bagsOnHand = (float) $bagBatches->sum(fn ($b) => (float) ($b->qty_remaining ?? $b->qty_received));
        $bagsBySupplier = $bagBatches->groupBy(fn ($b) => strtoupper($b->supplier_code ?: $this->guessSupplierCode($b->supplier_name)))
            ->map(fn ($g) => round((float) $g->sum(fn ($b) => (float) ($b->qty_remaining ?? $b->qty_received)), 2));

        // Finished warehouse (SKU bales / units) — column is `qty`, not qty_on_hand
        $finishedStock = (float) StockItem::where('item_type', 'sku')->sum('qty');

        // Production this month (bales = good_qty on packaging runs)
        $producedMonth = (float) PackagingRun::whereNotNull('run_end')
            ->whereBetween('run_end', ["{$monthStart} 00:00:00", "{$today} 23:59:59"])
            ->sum('good_qty');

        $productionTrend = PackagingRun::whereNotNull('run_end')
            ->whereBetween('run_end', ["{$trendStart} 00:00:00", "{$today} 23:59:59"])
            ->selectRaw('DATE(run_end) as date, SUM(good_qty) as bales')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'bales' => (float) $r->bales]);

        // Dispatch / sales / returns (driver trips) — returns = dispatched − sold
        $tripIds = \App\Models\DriverTrip::whereBetween('trip_date', [$monthStart, $today])
            ->whereNull('deleted_at')
            ->pluck('id');

        $dispatched = (float) DriverTripItem::whereIn('driver_trip_id', $tripIds)->sum('qty_carried_bales');
        $returnedLogged = (float) DriverTripItem::whereIn('driver_trip_id', $tripIds)->sum('qty_returned_bales');

        $saleIds = DB::table('driver_trip_sales')
            ->whereIn('driver_trip_id', $tripIds)
            ->whereNull('deleted_at')
            ->pluck('id');
        $sold = (float) DriverTripSaleItem::whereIn('driver_trip_sale_id', $saleIds)->sum('qty_bales');
        $returnsComputed = max(0, $dispatched - $sold);

        $inHouseSales = (float) Order::completedSale()
            ->whereBetween('order_date', [$monthStart, $today])
            ->whereNull('deleted_at')
            ->sum('total_amount');

        $tripSalesAmount = (float) DB::table('driver_trip_sales')
            ->whereIn('driver_trip_id', $tripIds)
            ->whereNull('deleted_at')
            ->sum('amount');

        $salesTrend = [];
        $cursor = \Carbon\Carbon::parse($trendStart);
        $end = \Carbon\Carbon::parse($today);
        $ordersByDay = Order::completedSale()->whereBetween('order_date', [$trendStart, $today])
            ->whereNull('deleted_at')
            ->selectRaw('order_date as date, SUM(total_amount) as amount')
            ->groupBy('date')->pluck('amount', 'date');
        $tripsByDay = DB::table('driver_trip_sales')
            ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')
            ->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$trendStart, $today])
            ->selectRaw('driver_trips.trip_date as date, SUM(driver_trip_sales.amount) as amount')
            ->groupBy('date')->pluck('amount', 'date');
        while ($cursor->lte($end)) {
            $d = $cursor->toDateString();
            $salesTrend[] = [
                'date' => $d,
                'amount' => round((float) ($ordersByDay[$d] ?? 0) + (float) ($tripsByDay[$d] ?? 0), 2),
            ];
            $cursor->addDay();
        }

        $conversions = SkuPackageConversion::with('sku:id,name,brand,size_liters')->get();

        return [
            'site' => $cfg['site'] ?? [],
            'stages' => $cfg['supply_chain_stages'] ?? [],
            'bottle_suppliers' => $cfg['bottle_suppliers'] ?? [],
            'bottle_transport_cost_kes' => (float) ($cfg['bottle_transport_cost_kes'] ?? 45000),
            'other_inputs' => $cfg['other_inputs'] ?? [],
            'salary_bands' => $salaryBands->values(),
            'payroll' => [
                'target_monthly_operational_kes' => round($targetMonthlyPayroll, 2),
                'actual_monthly_operational_kes' => $actualMonthlyPayroll,
                'director_allowance_kes' => (float) ($salaryBands->firstWhere('role_key', 'director')['default_kes'] ?? 60000),
                'director_excluded_from_payroll_export' => true,
                'headcount_operational' => $operationalStaff->count(),
                'by_role' => $staffByRole,
            ],
            'inventory' => [
                'bags_on_hand' => $bagsOnHand,
                'bags_by_supplier' => $bagsBySupplier,
                'package_unit' => 'bag',
                'note' => 'Empty bottles arrive in BAGS from FineLine & Blowplast (Nairobi). Bales start after production conversion.',
            ],
            'production' => [
                'bales_produced_month' => $producedMonth,
                'trend_30d' => $productionTrend,
            ],
            'warehouse' => [
                'finished_qty_on_hand' => $finishedStock,
                'unit' => 'bales / finished units',
            ],
            'dispatch_sales' => [
                'dispatched_bales_month' => $dispatched,
                'sold_bales_month' => $sold,
                'returned_bales_logged' => $returnedLogged,
                'returned_bales_computed' => $returnsComputed,
                'returns_formula' => 'dispatched_bales − sold_bales',
                'in_house_sales_kes' => round($inHouseSales, 2),
                'trip_sales_kes' => round($tripSalesAmount, 2),
                'sales_trend_30d' => $salesTrend,
            ],
            'conversions' => $conversions,
            'default_conversions' => $cfg['default_conversions'] ?? [],
            'automation_ideas' => $this->automationIdeas(),
            'exports' => [
                ['key' => 'payroll', 'label' => 'Payroll (Finalis-style, no Director)', 'path' => '/hr/payroll/runs/{id}/payroll-export', 'needs' => 'payroll_run_id'],
                ['key' => 'inventory_control', 'label' => 'Inventory Control Sheet', 'path' => '/operations/exports/inventory-control'],
                ['key' => 'raw_materials', 'label' => 'Raw Materials Usage', 'path' => '/operations/exports/raw-materials'],
                ['key' => 'production', 'label' => 'Production Data', 'path' => '/reports/production-export'],
                ['key' => 'warehouse', 'label' => 'Main Stock Warehouse Cards', 'path' => '/operations/exports/warehouse-stock'],
                ['key' => 'refills', 'label' => 'Refills', 'path' => '/operations/exports/refills'],
                ['key' => 'driver_worksheet', 'label' => 'Driver Work Sheet', 'path' => '/operations/exports/driver-worksheet'],
                ['key' => 'sales_control', 'label' => 'HSL Sales Control / Recon', 'path' => '/operations/exports/sales-control'],
                ['key' => 'debtors', 'label' => 'Debtors Ledger', 'path' => '/finance/debtors/export'],
            ],
        ];
    }

    /** Investor-safe: stage totals only — no salaries, no names. */
    public function investorOverview()
    {
        try {
            $d = $this->buildOverviewPayload();
            unset($d['salary_bands'], $d['payroll'], $d['conversions'], $d['exports'], $d['automation_ideas']);
            $d['note'] = 'High-level supply-chain snapshot. No payroll or staff detail.';
            return response()->json(['success' => true, 'data' => $d]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load operations overview',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function upsertConversion(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'supplier_code' => 'required|string|in:FINELINE,BLOWPLAST,OTHER',
            'sku_id' => 'required|exists:skus,id',
            'bottles_per_bag' => 'required|numeric|min:0.001',
            'bottles_per_bale' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $row = SkuPackageConversion::updateOrCreate(
            [
                'supplier_code' => strtoupper($request->supplier_code),
                'sku_id' => $request->sku_id,
            ],
            [
                'bottles_per_bag' => $request->bottles_per_bag,
                'bottles_per_bale' => $request->bottles_per_bale,
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]
        );

        return response()->json(['success' => true, 'data' => $row->load('sku:id,name,brand')]);
    }

    public function listSkus()
    {
        $skus = Sku::where('active', true)->orderBy('brand')->orderBy('name')
            ->get(['id', 'name', 'brand', 'size_liters']);
        return response()->json(['success' => true, 'data' => $skus]);
    }

    // --- Excel downloads ---

    public function exportInventoryControl(Request $request)
    {
        return (new OperationsExportService())->inventoryControl(
            (int) $request->get('year', now()->year),
            (int) $request->get('month', now()->month)
        );
    }

    public function exportRawMaterials(Request $request)
    {
        return (new OperationsExportService())->rawMaterials(
            (int) $request->get('year', now()->year),
            (int) $request->get('month', now()->month)
        );
    }

    public function exportWarehouseStock(Request $request)
    {
        return (new OperationsExportService())->warehouseStockCards(
            (int) $request->get('year', now()->year),
            (int) $request->get('month', now()->month)
        );
    }

    public function exportRefills(Request $request)
    {
        return (new OperationsExportService())->refills(
            (int) $request->get('year', now()->year),
            (int) $request->get('month', now()->month)
        );
    }

    public function exportDriverWorksheet(Request $request)
    {
        return (new OperationsExportService())->driverWorksheet(
            (int) $request->get('year', now()->year),
            (int) $request->get('month', now()->month)
        );
    }

    public function exportSalesControl(Request $request)
    {
        return (new OperationsExportService())->salesControl(
            (int) $request->get('year', now()->year),
            (int) $request->get('month', now()->month)
        );
    }

    private function guessSupplierCode(?string $name): string
    {
        $n = strtoupper((string) $name);
        if (str_contains($n, 'FINE')) {
            return 'FINELINE';
        }
        if (str_contains($n, 'BLOW')) {
            return 'BLOWPLAST';
        }
        return 'OTHER';
    }

    private function automationIdeas(): array
    {
        return [
            ['title' => 'Auto returns = dispatched − sold', 'detail' => 'Already computed on this overview; write qty_returned_bales on trip close so warehouse stock cards stay accurate without hand entry.'],
            ['title' => 'Low-bag reorder alerts', 'detail' => 'When FineLine/Blowplast bag stock falls below days-of-cover threshold, notify Director + Accountant with suggested order qty + KES 45,000 transport.'],
            ['title' => 'Production target → ladies pay', 'detail' => 'Link daily packaging targets to the 6 production staff pay band (max 12,500): auto-suggest commission/bonus lines on payroll draft.'],
            ['title' => 'Driver performance band', 'detail' => 'Scale Driver/Sales base 20k→25k from trip completion rate, fuel efficiency (km/L), and cash variance — feed Finalis Other Allowance automatically.'],
            ['title' => 'Nightly Excel pack', 'detail' => 'One scheduled job: Inventory Control + Production + Warehouse + Driver Work Sheet + Sales Control + Debtors into a dated zip (matches BackupService).'],
            ['title' => 'Consignment → conversion wizard', 'detail' => 'On GRN from FineLine/Blowplast, force supplier_code + bag qty; apply sku_package_conversions so production never mixes bag counts with bale targets.'],
            ['title' => 'Fuel & mileage anomalies', 'detail' => 'Extend Discrepancies: flag trips where KM vs fuel drawn exceeds fleet average by >20%.'],
            ['title' => 'Refill vs new-bottle mix', 'detail' => 'Auto-split in-house sales into refill SKUs vs sealed — powers REFILS.xlsx without a second ledger.'],
            ['title' => 'WhatsApp / SMS digests', 'detail' => 'Daily 6pm: production bales, bags left, returns, cash+M-Pesa collected — Director + Investor (Investor without payroll).'],
            ['title' => 'Android offline trip kit', 'detail' => 'Next: driver app caches prices + dispatched bales; sync sales photos/GPS when back online — same APIs as this web app.'],
        ];
    }
}
