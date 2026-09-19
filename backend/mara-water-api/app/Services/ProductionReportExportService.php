<?php

namespace App\Services;

use App\Models\PackagingRun;
use App\Models\Sku;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 9: "[MONTH] [YEAR] PRODUCTION DATA" layout -- DAY/DATE
 * down the left, brand-grouped size columns (from the live SKU catalog,
 * not the historical file's hardcoded sizes, so this doesn't go stale
 * as the catalog changes), one row per calendar day, sourced from
 * PackagingRun.good_qty grouped by day and SKU.
 */
class ProductionReportExportService
{
    public function generate(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Production');

        $bold = ['font' => ['bold' => true]];
        $title = ['font' => ['bold' => true, 'size' => 14]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $ws->setCellValue('A1', strtoupper($monthStart->format('F Y')) . ' PRODUCTION DATA');
        $ws->getStyle('A1')->applyFromArray($title);

        $skuGroups = Sku::where('active', true)->orderBy('name')->get()
            ->groupBy(fn ($s) => $s->brand ?: 'Uncategorized')
            ->sortBy(fn ($g, $brand) => in_array($brand, ['Premium', 'Platinum', 'Grace', 'Refill']) ? array_search($brand, ['Premium', 'Platinum', 'Grace', 'Refill']) : 99);

        $brandRow = 3;
        $sizeRow = 4;
        $ws->setCellValue('A' . $brandRow, 'DAY');
        $ws->setCellValue('B' . $brandRow, 'DATE');
        $ws->getStyle('A' . $brandRow)->applyFromArray($headerFill + $thin);
        $ws->getStyle('B' . $brandRow)->applyFromArray($headerFill + $thin);

        $col = 3;
        $skuCol = [];
        foreach ($skuGroups as $brand => $skus) {
            $startCol = $col;
            foreach ($skus as $sku) {
                $skuCol[$sku->id] = $col;
                $ws->setCellValue([$col, $sizeRow], $sku->name);
                $ws->getStyle([$col, $sizeRow])->applyFromArray($headerFill + $thin);
                $col++;
            }
            $ws->setCellValue([$startCol, $brandRow], $brand);
            if ($col - 1 > $startCol) {
                $ws->mergeCells([$startCol, $brandRow, $col - 1, $brandRow]);
            }
            $ws->getStyle([$startCol, $brandRow])->applyFromArray($headerFill + $thin);
        }
        $lastCol = $col - 1;

        $rows = PackagingRun::whereNotNull('run_end')
            ->whereBetween('run_end', [$monthStart->toDateString() . ' 00:00:00', $monthEnd->toDateString() . ' 23:59:59'])
            ->selectRaw('DATE(run_end) as day, sku_id, SUM(good_qty) as qty')
            ->groupBy('day', 'sku_id')
            ->get();

        $byDaySku = [];
        foreach ($rows as $r) {
            $byDaySku[$r->day][$r->sku_id] = (int) $r->qty;
        }

        $row = $sizeRow + 1;
        $firstDataRow = $row;
        $cursor = $monthStart->copy();
        while ($cursor->lte($monthEnd)) {
            $day = $cursor->toDateString();
            $ws->setCellValue('A' . $row, strtoupper($cursor->format('l')));
            $ws->setCellValue('B' . $row, $day);
            $ws->getStyle('B' . $row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            foreach ($skuCol as $skuId => $c) {
                $qty = $byDaySku[$day][$skuId] ?? null;
                if ($qty) {
                    $ws->setCellValue([$c, $row], $qty);
                }
            }
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($thin);
            }
            $cursor->addDay();
            $row++;
        }
        $lastDataRow = $row - 1;

        $ws->setCellValue('A' . $row, 'TOTAL');
        $ws->mergeCells('A' . $row . ':B' . $row);
        $ws->getStyle('A' . $row)->applyFromArray($bold);
        foreach ($skuCol as $skuId => $c) {
            $colLetter = Coordinate::stringFromColumnIndex($c);
            $ws->setCellValue([$c, $row], "=SUM({$colLetter}{$firstDataRow}:{$colLetter}{$lastDataRow})");
            $ws->getStyle([$c, $row])->applyFromArray($bold);
        }

        foreach (range('A', 'Z') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="production-' . $monthStart->format('Y-m') . '.xlsx"',
        ]);
    }
}
