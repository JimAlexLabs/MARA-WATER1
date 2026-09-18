<?php

namespace App\Services;

use App\Models\PayrollRun;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 9: the three exports from "Finalis Payroll Beta" --
 * Payroll, Payslips, and Bank Transfer Details -- each matching that
 * file's real sheet layout. All three read the same PayrollRun/Payslip
 * data (Round 2 Phase 4); this is purely the exact-format presentation
 * layer, generated server-side so a download and a future nightly
 * backup job produce identical files from one place.
 */
class PayrollExportService
{
    private const PAYROLL_HEADERS = [
        '#', 'Staff Number', 'Name', 'Basic Pay', 'House Allowance', 'Absentism',
        'Pensionable Pay', 'Overtime', 'Commission', 'Leave Pay', 'Telephone Allowance',
        'Other Allowance', 'Gross Pay', 'Company Contribution to NSSF', 'NSSF Employee Contribution',
        'Additional Voluntary Contribution', 'Total Pension Contribution', 'Taxable Pay',
        'Tax Payable', 'SHIF', 'Housing Levy', 'Total Deductions', 'Net Salary',
    ];

    public function payrollSheet(PayrollRun $run): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Payroll');

        $bold = ['font' => ['bold' => true]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $ws->setCellValue('D4', 'Homa Springs Limited');
        $ws->setCellValue('F4', 'Staff Nos.');
        $ws->setCellValue('H4', $run->payslips->count());
        $ws->setCellValue('D5', $run->month->toDateString());
        $ws->setCellValue('D6', 'Payroll Computations');
        $ws->setCellValue('F6', 'Gross Salaries');
        $ws->setCellValue('H6', round((float) $run->payslips->sum('gross_pay'), 2));
        foreach (['D4', 'F4', 'D6', 'F6'] as $cell) {
            $ws->getStyle($cell)->applyFromArray($bold);
        }

        $headerRow = 9;
        foreach (self::PAYROLL_HEADERS as $i => $label) {
            $ws->setCellValue([$i + 1, $headerRow], $label);
            $ws->getStyle([$i + 1, $headerRow])->applyFromArray($headerFill + $thin);
        }

        $row = $headerRow + 1;
        foreach ($run->payslips as $i => $p) {
            $u = $p->user;
            $values = [
                $i + 1, $u->staff_number, $u->full_name, $p->basic_pay, $p->house_allowance, $p->absentism_deduction,
                $p->pensionable_pay, $p->overtime_pay, $p->commission, $p->leave_pay, $p->telephone_allowance,
                $p->other_allowance, $p->gross_pay, $p->company_nssf, $p->employee_nssf,
                $p->avc, $p->total_pension_contribution, $p->taxable_pay,
                $p->tax_payable, $p->shif, $p->housing_levy, $p->total_deductions, $p->net_salary,
            ];
            foreach ($values as $c => $v) {
                $ws->setCellValue([$c + 1, $row], $v);
                $ws->getStyle([$c + 1, $row])->applyFromArray($thin);
            }
            $row++;
        }

        foreach (range('A', 'W') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $this->stream($spreadsheet, "payroll-{$run->month->format('Y-m')}.xlsx");
    }

    public function payslips(PayrollRun $run): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Payslips');

        $bold = ['font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        // One payslip "card" per employee, stacked vertically -- simpler
        // and more reliably printable than the source's side-by-side
        // 4-per-row layout, while keeping the same field set per card.
        $row = 1;
        foreach ($run->payslips as $p) {
            $u = $p->user;
            $ws->setCellValue("A{$row}", 'Payslip for the month of:');
            $ws->setCellValue("C{$row}", $run->month->toDateString());
            $ws->getStyle("A{$row}")->applyFromArray($bold);
            $row++;
            foreach ([
                'Name' => $u->full_name,
                'Personnel No:' => $u->staff_number,
                'ID No' => $u->id_number,
                'Department' => optional($u->department)->name,
            ] as $label => $value) {
                $ws->setCellValue("A{$row}", $label);
                $ws->setCellValue("C{$row}", $value);
                $row++;
            }
            foreach ([
                'Basic Pay' => $p->basic_pay, 'House Allowance' => $p->house_allowance,
                'Overtime' => $p->overtime_pay, 'Commission' => $p->commission,
                'Leave Pay' => $p->leave_pay, 'Telephone Allowance' => $p->telephone_allowance,
                'Other Allowance' => $p->other_allowance, 'Gross Pay' => $p->gross_pay,
                'NSSF (Employee)' => $p->employee_nssf, 'SHIF' => $p->shif,
                'Housing Levy' => $p->housing_levy, 'PAYE' => $p->paye,
                'Total Deductions' => $p->total_deductions, 'Net Salary' => $p->net_salary,
            ] as $label => $value) {
                $ws->setCellValue("A{$row}", $label);
                $ws->setCellValue("C{$row}", (float) $value);
                $isNet = $label === 'Net Salary';
                $ws->getStyle("A{$row}:C{$row}")->applyFromArray($isNet ? $bold : $thin);
                $row++;
            }
            $row += 2;
        }

        foreach (range('A', 'D') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $this->stream($spreadsheet, "payslips-{$run->month->format('Y-m')}.xlsx");
    }

    public function bankTransferFile(PayrollRun $run): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Bank Transfer Details');

        $bold = ['font' => ['bold' => true]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $ws->setCellValue('B4', $run->month->toDateString());
        $ws->setCellValue('C4', 'BANK TRANSFER DETAILS');
        $ws->setCellValue('E4', 'Homa Springs Limited');
        $ws->getStyle('C4')->applyFromArray($bold);

        $headerRow = 6;
        $headers = ['No.', 'NAME', 'ACCOUNT NUMBER', 'BANK', 'BRANCH', 'BANK CODE', 'AMOUNT', 'MONTH', 'Department'];
        foreach ($headers as $i => $label) {
            $ws->setCellValue([$i + 1, $headerRow], $label);
            $ws->getStyle([$i + 1, $headerRow])->applyFromArray($headerFill + $thin);
        }

        $row = $headerRow + 1;
        foreach ($run->payslips as $i => $p) {
            $u = $p->user;
            $values = [
                $i + 1, $u->full_name, $u->bank_account_number, $u->bank_name, $u->bank_branch,
                $u->bank_code, (float) $p->net_salary, $run->month->toDateString(), optional($u->department)->name,
            ];
            foreach ($values as $c => $v) {
                $ws->setCellValue([$c + 1, $row], $v);
                $ws->getStyle([$c + 1, $row])->applyFromArray($thin);
            }
            $row++;
        }

        foreach (range('A', 'I') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $this->stream($spreadsheet, "bank-transfer-{$run->month->format('Y-m')}.xlsx");
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
