<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HypothesisModelService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HypothesisModelController extends Controller
{
    public function show(Request $request)
    {
        $overrides = $request->input('overrides');
        $snapshot = app(HypothesisModelService::class)->snapshot(is_array($overrides) ? $overrides : null);

        return response()->json([
            'success' => true,
            'message' => 'Hypothesis model (Intripet + NEOPACK + bailing + wages + transport + payroll), current suggested prices.',
            'data' => $snapshot,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $snapshot = app(HypothesisModelService::class)->snapshot(
            is_array($request->input('overrides')) ? $request->input('overrides') : null
        );

        $sheet = new Spreadsheet();
        $assumptions = $sheet->getActiveSheet();
        $assumptions->setTitle('Assumptions');
        $assumptions->setCellValue('A1', 'MARA WATER — Hypothesis model (live app export)');
        $assumptions->setCellValue('A2', 'Bottle supplier');
        $assumptions->setCellValue('B2', $snapshot['trip']['supplier'] ?? 'Intripet Limited');
        $assumptions->setCellValue('A3', 'Transport / trip (KES)');
        $assumptions->setCellValue('B3', $snapshot['trip']['transport_per_trip'] ?? 45000);

        $row = 5;
        $assumptions->setCellValue("A{$row}", 'Suggested selling prices (KES / bale or unit)');
        $row++;
        foreach ($snapshot['prices'] as $brand => $sizes) {
            foreach ($sizes as $size => $price) {
                $assumptions->setCellValue("A{$row}", "{$brand} {$size}L");
                $assumptions->setCellValue("B{$row}", $price);
                $row++;
            }
        }

        $cost = $sheet->createSheet();
        $cost->setTitle('Cost Build');
        $cost->fromArray(
            ['Size', 'Materials/btl', 'Wage/btl', 'Transport/btl', 'COP/btl', 'Price/bale', 'Price/btl', 'Margin/btl'],
            null,
            'A1'
        );
        $r = 2;
        foreach ($snapshot['cost_build'] as $line) {
            $cost->fromArray([
                $line['size'],
                $line['materials_per_bottle'],
                $line['wage_per_bottle'],
                $line['transport_per_bottle'],
                $line['cost_of_production_per_bottle'],
                $line['price_per_bale'],
                $line['price_per_bottle'],
                $line['gross_margin_per_bottle'],
            ], null, "A{$r}");
            $r++;
        }

        $trip = $sheet->createSheet();
        $trip->setTitle('Trip P&L');
        $trip->fromArray(['SKU', 'Qty bottles', 'Cost/btl', 'Price/btl', 'Revenue', 'Cost total'], null, 'A1');
        $r = 2;
        foreach ($snapshot['trip']['lines'] as $line) {
            $trip->fromArray([
                $line['sku'], $line['qty_bottles'], $line['cost_per_bottle'],
                $line['price_per_bottle'], $line['revenue'], $line['cost_total'],
            ], null, "A{$r}");
            $r++;
        }
        $trip->setCellValue("A{$r}", 'TOTAL');
        $trip->setCellValue("E{$r}", $snapshot['trip']['revenue']);
        $trip->setCellValue("F{$r}", $snapshot['trip']['cost_total']);

        $monthly = $sheet->createSheet();
        $monthly->setTitle('Monthly');
        $monthly->fromArray(['Trips', 'Revenue', 'COGS', 'Gross', 'Jim stipend', 'Payroll low', 'Payroll high', 'Overheads', 'Net low', 'Net high'], null, 'A1');
        $r = 2;
        foreach ($snapshot['monthly'] as $line) {
            $monthly->fromArray([
                $line['trips'], $line['revenue'], $line['cost_of_production'], $line['gross_margin'],
                $line['jim_stipend'], $line['payroll_low'], $line['payroll_high'], $line['overheads'],
                $line['net_low'], $line['net_high'],
            ], null, "A{$r}");
            $r++;
        }

        $cap = $sheet->createSheet();
        $cap->setTitle('Capacity');
        $cap->setCellValue('A1', 'Bottles / hour');
        $cap->setCellValue('B1', $snapshot['capacity']['bottles_per_hour'] ?? 2400);
        $cap->setCellValue('A2', 'Hours / day');
        $cap->setCellValue('B2', $snapshot['capacity']['hours_per_day'] ?? 4);
        $cap->setCellValue('A3', 'Bottles / day');
        $cap->setCellValue('B3', $snapshot['capacity']['bottles_per_day'] ?? 9600);
        $cap->setCellValue('A4', 'Days to fill one trip');
        $cap->setCellValue('B4', $snapshot['capacity']['days_to_fill_trip'] ?? '');
        $cap->setCellValue('A5', 'Trip bottles total');
        $cap->setCellValue('B5', $snapshot['trip']['bottles_total'] ?? '');

        $split = $sheet->createSheet();
        $split->setTitle('Profit Split');
        $split->setCellValue('A1', 'Active phase');
        $split->setCellValue('B1', $snapshot['profit_split']['phase'] ?? '');
        $split->fromArray(['Partner', 'Pct', 'Amount (net high @ assumed trips)'], null, 'A3');
        $r = 4;
        foreach (($snapshot['profit_split']['rows'] ?? []) as $line) {
            $split->fromArray([$line['name'], $line['pct'], $line['amount']], null, "A{$r}");
            $r++;
        }
        $r += 1;
        $split->setCellValue("A{$r}", 'All phases (pct)');
        $r++;
        foreach (($snapshot['profit_split']['all_phases'] ?? []) as $phase => $partners) {
            foreach ($partners as $name => $pct) {
                $split->fromArray([$phase, $name, round((float) $pct * 100, 1)], null, "A{$r}");
                $r++;
            }
        }

        $filename = 'mara-hypothesis-model-'.now('Africa/Nairobi')->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($sheet) {
            (new Xlsx($sheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
