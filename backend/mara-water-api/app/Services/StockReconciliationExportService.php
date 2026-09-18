<?php

namespace App\Services;

use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\StockMove;
use App\Models\Warehouse;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 9: "FINISHED STOCK RECONCILIATION TOOL" layout -- brand-
 * grouped rows (Item Code/Description/Unit/Opening/Produced/Issued/
 * Returned/Closing qty + value columns), for one warehouse over one
 * date range. The real template had one sheet per day (31 sheets for a
 * month); this generates one sheet per day requested, capped at 31 to
 * keep generation bounded.
 */
class StockReconciliationExportService
{
    public function generate(string $warehouseId, string $dateFrom, string $dateTo): StreamedResponse
    {
        $warehouse = Warehouse::findOrFail($warehouseId);
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();
        $days = min($from->diffInDays($to) + 1, 31);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $skus = Sku::where('active', true)->orderBy('brand')->orderBy('name')->get();
        $prices = PriceListItem::whereHas('priceList', fn ($q) => $q->where('is_default', true))
            ->pluck('unit_price', 'sku_id');

        for ($i = 0; $i < $days; $i++) {
            $day = $from->copy()->addDays($i);
            $this->buildDaySheet($spreadsheet, $warehouse, $skus, $day, $prices);
        }

        $writer = new Xlsx($spreadsheet);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"stock-reconciliation-{$warehouse->code}-{$from->toDateString()}-to-{$to->toDateString()}.xlsx\"",
        ]);
    }

    private function buildDaySheet(Spreadsheet $spreadsheet, Warehouse $warehouse, $skus, Carbon $day, $prices): void
    {
        $ws = $spreadsheet->createSheet();
        $ws->setTitle($day->format('d M'));

        $bold = ['font' => ['bold' => true]];
        $title = ['font' => ['bold' => true, 'size' => 14]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $ws->setCellValue('C4', 'HOMA SPRINGS LIMITED');
        $ws->getStyle('C4')->applyFromArray($bold);
        $ws->setCellValue('A8', 'FINISHED STOCK RECONCILIATION TOOL -- ' . $warehouse->name . ' -- ' . $day->toDateString());
        $ws->getStyle('A8')->applyFromArray($title);

        $headerRow = 9;
        $headers = ['Item Code', 'Item Description', 'Unit', 'Opening Qty', 'Stock Produced', 'Issued Qty', 'Returned Qty', 'Closing Qty', 'Unit Price', 'Opening Value', 'Value Produced', 'Issued Value', 'Returned Value', 'Closing Value'];
        foreach ($headers as $i => $label) {
            $ws->setCellValue([$i + 1, $headerRow], $label);
            $ws->getStyle([$i + 1, $headerRow])->applyFromArray($headerFill + $thin);
        }

        $skuIds = $skus->pluck('id');
        $opening = $this->netMovesBefore($skuIds, $warehouse->id, $day->copy()->startOfDay());
        $produced = $this->movesOfType($skuIds, $warehouse->id, $day, 'produce');
        $issued = $this->movesOfType($skuIds, $warehouse->id, $day, 'issue');
        $returned = $this->movesOfType($skuIds, $warehouse->id, $day, 'return');

        $row = $headerRow + 1;
        $currentBrand = null;
        $brandStartRow = null;
        foreach ($skus as $sku) {
            if ($sku->brand !== $currentBrand) {
                if ($currentBrand !== null && $row - 1 > $brandStartRow) {
                    $ws->mergeCells([2, $brandStartRow, 2, $row - 1]);
                }
                $currentBrand = $sku->brand;
                $brandStartRow = $row;
            }

            $op = $opening[$sku->id] ?? 0.0;
            $pr = $produced[$sku->id] ?? 0.0;
            $is = $issued[$sku->id] ?? 0.0;
            $rt = $returned[$sku->id] ?? 0.0;
            $close = $op + $pr - $is + $rt;
            $price = (float) ($prices[$sku->id] ?? 0);

            $ws->setCellValue([1, $row], $sku->code);
            $ws->setCellValue([2, $row], $sku->brand ?: 'Mara Water');
            $ws->setCellValue([3, $row], $sku->name);
            $ws->setCellValue([4, $row], $sku->unit ?? 'BOTTLE');
            $ws->setCellValue([5, $row], $op);
            $ws->setCellValue([6, $row], $pr);
            $ws->setCellValue([7, $row], $is);
            $ws->setCellValue([8, $row], $rt);
            $ws->setCellValue([9, $row], $close);
            $ws->setCellValue([10, $row], $price);
            $ws->setCellValue([11, $row], round($op * $price, 2));
            $ws->setCellValue([12, $row], round($pr * $price, 2));
            $ws->setCellValue([13, $row], round($is * $price, 2));
            $ws->setCellValue([14, $row], round($rt * $price, 2));
            $ws->setCellValue([15, $row], round($close * $price, 2));
            for ($c = 1; $c <= 15; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($thin);
            }
            $row++;
        }
        if ($currentBrand !== null && $row - 1 > $brandStartRow) {
            $ws->mergeCells([2, $brandStartRow, 2, $row - 1]);
        }

        foreach (range('A', 'O') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
    }

    /** sku_id => net qty (in - out) from every stock move before $before, at this warehouse. */
    private function netMovesBefore($skuIds, string $warehouseId, Carbon $before): array
    {
        $in = StockMove::whereNull('deleted_at')->where('item_type', 'sku')->whereIn('sku_id', $skuIds)
            ->where('warehouse_to_id', $warehouseId)->where('moved_at', '<', $before)
            ->selectRaw('sku_id, SUM(qty) as qty')->groupBy('sku_id')->pluck('qty', 'sku_id');
        $out = StockMove::whereNull('deleted_at')->where('item_type', 'sku')->whereIn('sku_id', $skuIds)
            ->where('warehouse_from_id', $warehouseId)->where('moved_at', '<', $before)
            ->selectRaw('sku_id, SUM(qty) as qty')->groupBy('sku_id')->pluck('qty', 'sku_id');

        $net = [];
        foreach ($skuIds as $id) {
            $net[$id] = (float) ($in[$id] ?? 0) - (float) ($out[$id] ?? 0);
        }
        return $net;
    }

    /** sku_id => qty for a specific move_type on this day, at this warehouse (either direction). */
    private function movesOfType($skuIds, string $warehouseId, Carbon $day, string $moveType): array
    {
        $rows = StockMove::whereNull('deleted_at')->where('item_type', 'sku')->whereIn('sku_id', $skuIds)
            ->where('move_type', $moveType)
            ->where(function ($q) use ($warehouseId) {
                $q->where('warehouse_to_id', $warehouseId)->orWhere('warehouse_from_id', $warehouseId);
            })
            ->whereBetween('moved_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->selectRaw('sku_id, SUM(qty) as qty')->groupBy('sku_id')->pluck('qty', 'sku_id');

        $out = [];
        foreach ($skuIds as $id) {
            $out[$id] = (float) ($rows[$id] ?? 0);
        }
        return $out;
    }
}
