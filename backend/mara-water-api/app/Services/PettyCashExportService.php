<?php

namespace App\Services;

use App\Models\PettyCashEntry;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 9: "PETTY CASH - JOURNAL DATA SHEET" layout, matching
 * the real Petty Cash Summary file's monthly sheets -- title/site block,
 * then DATE/M-PESA REF/ACCOUNT CODE/ACCOUNT DESCRIPTION/TRANSACTION
 * DESCRIPTION/REQUESTOR/IN/OUT/BALANCE columns with a running balance.
 * There's no per-transaction "charge" amount tracked separately from
 * amount_in/amount_out in this app, so the source's TRANSACTION AMOUNT
 * column (an M-Pesa fee) is left out rather than shown as a fake zero.
 */
class PettyCashExportService
{
    public function generate(string $dateFrom, string $dateTo): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Petty Cash');

        $bold = ['font' => ['bold' => true]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $from = Carbon::parse($dateFrom);
        $to = Carbon::parse($dateTo);

        $ws->setCellValue('F1', 'HOMA SPRINGS LIMITED');
        $ws->setCellValue('F2', 'PETTY CASH - JOURNAL DATA SHEET');
        $ws->setCellValue('F3', 'MONTHLY PETTY CASH RECORD');
        $ws->setCellValue('F4', 'SITE NAME: MARA DRINKING WATER');
        $ws->setCellValue('F5', 'PERIOD: ' . $from->format('d-m-Y') . ' to ' . $to->format('d-m-Y'));
        foreach (['F1', 'F2', 'F3', 'F4', 'F5'] as $cell) {
            $ws->getStyle($cell)->applyFromArray($bold);
        }

        $headerRow = 8;
        $headers = ['DATE', 'M-PESA REF', 'ACCOUNT CODE', 'ACCOUNT DESCRIPTION', 'TRANSACTION DESCRIPTION', 'REQUESTOR', 'IN', 'OUT', 'BALANCE'];
        foreach ($headers as $i => $label) {
            $ws->setCellValue([$i + 2, $headerRow], $label); // starts at column B, matching the source
            $ws->getStyle([$i + 2, $headerRow])->applyFromArray($headerFill + $thin);
        }

        $entries = PettyCashEntry::with('account')->whereNull('deleted_at')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entry_date')->orderBy('created_at')->get();

        $row = $headerRow + 1;
        $balance = 0;
        $totalIn = 0;
        $totalOut = 0;
        foreach ($entries as $entry) {
            $balance += (float) $entry->amount_in - (float) $entry->amount_out;
            $totalIn += (float) $entry->amount_in;
            $totalOut += (float) $entry->amount_out;

            $ws->setCellValue([2, $row], $entry->entry_date?->toDateString());
            $ws->getStyle([2, $row])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $ws->setCellValue([3, $row], $entry->mpesa_reference);
            $ws->setCellValue([4, $row], $entry->account->code ?? null);
            $ws->setCellValue([5, $row], $entry->account->description ?? null);
            $ws->setCellValue([6, $row], $entry->description);
            $ws->setCellValue([7, $row], $entry->requestor_name);
            $ws->setCellValue([8, $row], (float) $entry->amount_in ?: null);
            $ws->setCellValue([9, $row], (float) $entry->amount_out ?: null);
            $ws->setCellValue([10, $row], $balance);
            for ($c = 2; $c <= 10; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($thin);
            }
            $row++;
        }

        $ws->setCellValue([6, $row], 'TOTAL');
        $ws->setCellValue([8, $row], $totalIn);
        $ws->setCellValue([9, $row], $totalOut);
        $ws->setCellValue([10, $row], $balance);
        for ($c = 6; $c <= 10; $c++) {
            $ws->getStyle([$c, $row])->applyFromArray($bold);
        }

        foreach (range('A', 'J') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"petty-cash-{$from->format('Y-m-d')}-to-{$to->format('Y-m-d')}.xlsx\"",
        ]);
    }
}
