<?php

namespace App\Services;

use App\Models\StockReconciliationReport;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Round 5B Phase 3: persist weekly/monthly (and on-demand) stock
 * reconciliation Excels so they can be downloaded later without
 * regenerating, matching Stock Reconciliation Template layout via
 * StockReconciliationExportService.
 */
class StockReconciliationReportService
{
    public function __construct(private StockReconciliationExportService $exporter)
    {
    }

    public function generateAndStore(
        string $periodType,
        string $dateFrom,
        string $dateTo,
        ?string $warehouseId = null,
        ?string $generatedBy = null
    ): StockReconciliationReport {
        $warehouseId = $warehouseId ?: Warehouse::orderBy('code')->value('id');
        if (!$warehouseId) {
            throw new \RuntimeException('No warehouse available for stock reconciliation');
        }

        $binary = $this->exporter->generateBinary($warehouseId, $dateFrom, $dateTo);
        $warehouse = Warehouse::find($warehouseId);
        $filename = sprintf(
            'stock-reconciliation-%s-%s-%s-to-%s.xlsx',
            $periodType,
            $warehouse->code ?? 'WH',
            $dateFrom,
            $dateTo
        );

        return StockReconciliationReport::create([
            'period_type' => $periodType,
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'warehouse_id' => $warehouseId,
            'filename' => $filename,
            'size_bytes' => strlen($binary),
            'payload' => base64_encode($binary),
            'generated_by' => $generatedBy ?? Auth::id() ?? 'scheduler',
        ]);
    }

    public function runWeekly(?string $asOf = null): StockReconciliationReport
    {
        $end = Carbon::parse($asOf ?? now())->endOfDay();
        $start = $end->copy()->subDays(6)->startOfDay();
        return $this->generateAndStore('weekly', $start->toDateString(), $end->toDateString(), null, 'scheduler');
    }

    public function runMonthly(?string $asOf = null): StockReconciliationReport
    {
        $end = Carbon::parse($asOf ?? now())->endOfMonth()->endOfDay();
        $start = $end->copy()->startOfMonth()->startOfDay();
        // Cap at 31 days inside the export service already.
        return $this->generateAndStore('monthly', $start->toDateString(), $end->toDateString(), null, 'scheduler');
    }
}
