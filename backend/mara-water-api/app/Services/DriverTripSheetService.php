<?php

namespace App\Services;

use App\Models\DriverTrip;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 2: the Dispatch Sheet (generated the moment "Start
 * Trip" locks the dispatch) and Return/Reconciliation Sheet (generated
 * the moment "End Trip" closes the trip) -- both derived from the one
 * trip record, no duplicate data entry. Layout here is a reasonable
 * first cut (header block + per-brand/size grid + signature lines);
 * Round 3 Phase 9 replaces this with an exact match of the physical
 * driver worksheet's layout once that template's precise structure is
 * built out -- this service is where that later formatting work lands,
 * so both sheets keep coming from this one place rather than a second
 * copy of the formatting logic.
 */
class DriverTripSheetService
{
    public function dispatchSheet(DriverTrip $trip): StreamedResponse
    {
        $sheet = $this->buildSheet($trip, 'dispatch');
        return $this->stream($sheet, "dispatch-sheet-{$trip->trip_date->toDateString()}-{$trip->vehicle->reg_no}.xlsx");
    }

    public function returnSheet(DriverTrip $trip): StreamedResponse
    {
        $sheet = $this->buildSheet($trip, 'return');
        return $this->stream($sheet, "return-sheet-{$trip->trip_date->toDateString()}-{$trip->vehicle->reg_no}.xlsx");
    }

    private function buildSheet(DriverTrip $trip, string $mode): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle($mode === 'dispatch' ? 'Dispatch Sheet' : 'Return Sheet');

        $bold = ['font' => ['bold' => true]];
        $title = ['font' => ['bold' => true, 'size' => 14]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thinBorder = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $row = 1;
        $ws->setCellValue("A{$row}", 'HOMA SPRINGS LIMITED -- MARA DRINKING WATER');
        $ws->getStyle("A{$row}")->applyFromArray($title);
        $row++;
        $ws->setCellValue("A{$row}", $mode === 'dispatch' ? 'DISPATCH SHEET' : 'RETURN / RECONCILIATION SHEET');
        $ws->getStyle("A{$row}")->applyFromArray($bold);
        $row += 2;

        $headerBlock = [
            'Date' => $trip->trip_date->toDateString(),
            'Vehicle' => $trip->vehicle->reg_no ?? '',
            'Route' => $trip->route ?? '',
            'Warehouse' => $trip->warehouse->name ?? '',
            'Driver' => $trip->driver->full_name ?? '',
            'Authorizing Officer' => $trip->authorizingOfficer->full_name ?? '',
            'Mileage Start' => $trip->mileage_start,
        ];
        if ($mode === 'dispatch') {
            $headerBlock['Departure Time'] = $trip->time_out ?? '';
        } else {
            $headerBlock['Mileage End'] = $trip->mileage_end;
            $headerBlock['KM Covered'] = $trip->km_covered;
            $headerBlock['Return Time'] = $trip->time_in ?? '';
        }

        foreach ($headerBlock as $label => $value) {
            $ws->setCellValue("A{$row}", $label . ':');
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $ws->setCellValue("B{$row}", $value);
            $row++;
        }
        $row++;

        // Per-brand/size grid.
        $gridHeaderRow = $row;
        $columns = $mode === 'dispatch'
            ? ['Brand', 'Size', 'Dispatched', 'Dispatched (bales)', 'Unit Price']
            : ['Brand', 'Size', 'Dispatched', 'Returned', 'Returned (bales)', 'Sold', 'Unit Price', 'Line Total'];
        $col = 'A';
        foreach ($columns as $label) {
            $ws->setCellValue("{$col}{$gridHeaderRow}", $label);
            $ws->getStyle("{$col}{$gridHeaderRow}")->applyFromArray($headerFill + $thinBorder);
            $col++;
        }
        $row++;

        foreach ($trip->items as $item) {
            $sku = $item->sku;
            $col = 'A';
            $cells = $mode === 'dispatch'
                ? [$sku->brand ?? 'Mara Water', $sku->name, $item->qty_carried, $item->qty_carried_bales, $item->unit_price]
                : [
                    $sku->brand ?? 'Mara Water', $sku->name, $item->qty_carried, $item->qty_returned, $item->qty_returned_bales,
                    $item->qty_sold, $item->unit_price, round($item->qty_sold * (float) $item->unit_price, 2),
                ];
            foreach ($cells as $value) {
                $ws->setCellValue("{$col}{$row}", $value);
                $ws->getStyle("{$col}{$row}")->applyFromArray($thinBorder);
                $col++;
            }
            $row++;
        }
        $row++;

        if ($mode === 'return') {
            $recon = $trip->reconciliation;
            $ws->setCellValue("A{$row}", 'Expected Revenue:');
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $ws->setCellValue("B{$row}", $recon['expected_revenue']);
            $row++;
            $ws->setCellValue("A{$row}", 'Collected (Cash+M-Pesa+Debt):');
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $ws->setCellValue("B{$row}", $recon['collected']);
            $row++;
            $ws->setCellValue("A{$row}", 'Variance:');
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $ws->setCellValue("B{$row}", $recon['variance']);
            $row++;
            $ws->setCellValue("A{$row}", 'Discrepancy Flag:');
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $ws->setCellValue("B{$row}", $trip->has_discrepancy ? 'YES -- see stock/money mismatch above' : 'None');
            if ($trip->has_discrepancy) {
                $ws->getStyle("B{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'CC0000']]]);
            }
            $row += 2;
        }

        $row++;
        $ws->setCellValue("A{$row}", 'Driver Signature: _____________________________');
        $ws->setCellValue("D{$row}", 'Date: ______________');
        $row += 3;
        $ws->setCellValue("A{$row}", 'Authorizing Officer Signature: _____________________________');
        $ws->setCellValue("D{$row}", 'Date: ______________');

        foreach (range('A', 'H') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function stream(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $writer = new Xlsx($spreadsheet);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
