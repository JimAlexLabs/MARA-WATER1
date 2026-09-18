<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DebtorLedgerEntry;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 9: "HOMA SPRINGS DEBTORS LEDGER" layout -- one titled
 * block per customer (name, column headers, chronological entries with
 * a running balance, a TOTALS row), matching the real HSL Debtors
 * Ledger file. Only customers with at least one ledger entry get a
 * block -- an empty block for every customer that's never had a debt
 * would just be noise.
 */
class DebtorsLedgerExportService
{
    public function generate(?string $fiscalYearLabel = null): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Debtors Ledger');

        $bold = ['font' => ['bold' => true]];
        $title = ['font' => ['bold' => true, 'size' => 14]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $row = 1;
        $ws->setCellValue("A{$row}", 'HOMA SPRINGS DEBTORS LEDGER');
        $ws->mergeCells("A{$row}:G{$row}");
        $ws->getStyle("A{$row}")->applyFromArray($title);
        $row++;
        $ws->setCellValue("A{$row}", $fiscalYearLabel ?? ('FY ' . now()->year));
        $ws->mergeCells("A{$row}:G{$row}");
        $row += 2;

        $customerIds = DebtorLedgerEntry::whereNull('deleted_at')->distinct()->pluck('customer_id');
        $customers = Customer::whereIn('id', $customerIds)->whereNull('deleted_at')->orderBy('name')->get();

        foreach ($customers as $customer) {
            $ws->setCellValue("A{$row}", $customer->name);
            $ws->mergeCells("A{$row}:G{$row}");
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $row++;

            foreach (['DATE', 'DETAILS', 'CHQ/ INV.NO', 'P.V N0.', 'DEBIT', 'CREDIT', 'BALANCE'] as $i => $label) {
                $ws->setCellValue([$i + 1, $row], $label);
                $ws->getStyle([$i + 1, $row])->applyFromArray($headerFill + $thin);
            }
            $row++;

            $entries = DebtorLedgerEntry::whereNull('deleted_at')->where('customer_id', $customer->id)
                ->orderBy('entry_date')->orderBy('created_at')->get();

            $balance = 0;
            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($entries as $entry) {
                $balance += (float) $entry->debit - (float) $entry->credit;
                $totalDebit += (float) $entry->debit;
                $totalCredit += (float) $entry->credit;

                $ws->setCellValue([1, $row], $entry->entry_date?->toDateString());
                $ws->getStyle([1, $row])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                $ws->setCellValue([2, $row], $entry->details);
                $ws->setCellValue([3, $row], $entry->reference_no);
                $ws->setCellValue([4, $row], $entry->voucher_no);
                $ws->setCellValue([5, $row], (float) $entry->debit ?: null);
                $ws->setCellValue([6, $row], (float) $entry->credit ?: null);
                $ws->setCellValue([7, $row], $balance);
                for ($c = 1; $c <= 7; $c++) {
                    $ws->getStyle([$c, $row])->applyFromArray($thin);
                }
                $row++;
            }

            $ws->setCellValue([1, $row], 'TOTALS');
            $ws->setCellValue([5, $row], $totalDebit);
            $ws->setCellValue([6, $row], $totalCredit);
            $ws->setCellValue([7, $row], $balance);
            for ($c = 1; $c <= 7; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($bold);
            }
            $row += 2;
        }

        foreach (range('A', 'G') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="debtors-ledger-' . now()->format('Y-m-d') . '.xlsx"',
        ]);
    }
}
