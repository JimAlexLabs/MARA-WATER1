<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OperationsModelService;
use App\Services\ReorderAdvisorService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin (director tier) only — MARA WATER Hypothesis Model + reorder advisor.
 */
class OperationsModelController extends Controller
{
    public function __construct(
        private readonly OperationsModelService $model,
        private readonly ReorderAdvisorService $advisor,
    ) {
    }

    public function show()
    {
        return response()->json(['success' => true, 'data' => $this->model->snapshot()]);
    }

    public function update(Request $request)
    {
        $request->validate(['assumptions' => 'required|array']);
        $saved = $this->model->save($request->input('assumptions'));

        return response()->json([
            'success' => true,
            'message' => 'Model assumptions saved — every sheet recalculated.',
            'data' => $this->model->snapshot($saved),
        ]);
    }

    public function reset()
    {
        $defaults = $this->model->reset();

        return response()->json([
            'success' => true,
            'message' => 'Model reset to Excel defaults.',
            'data' => $this->model->snapshot($defaults),
        ]);
    }

    public function advisor()
    {
        try {
            return response()->json(['success' => true, 'data' => $this->advisor->build()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not build the reorder advisor.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function export(): StreamedResponse
    {
        $snap = $this->model->snapshot();
        $a = $snap['assumptions'];
        $c = $snap['computed'];
        $book = new Spreadsheet();

        // Assumptions
        $ws = $book->getActiveSheet();
        $ws->setTitle('Assumptions');
        $rows = [['MARA WATER — Assumptions (live app export; selling prices are the application price list)']];
        $rows[] = [];
        $rows[] = ['BOTTLE + CAP (Intripet, ex-VAT)'];
        foreach (OperationsModelService::BOTTLED as $s) {
            $rows[] = ["Bottle {$s} ex-VAT", $a['bottle_ex_vat'][$s]];
        }
        $rows[] = ['Cap ex-VAT (all sizes)', $a['cap_ex_vat']];
        $rows[] = ['VAT rate', $a['vat_rate']];
        foreach (['5L', '10L', '20L'] as $s) {
            $rows[] = ["Bottle+cap {$s}, final (Blowplast quote)", $a['bottle_cap_final'][$s]];
        }
        $rows[] = [];
        $rows[] = ['LABELS (NEOPACK, ex-VAT)'];
        foreach (OperationsModelService::BOTTLED as $s) {
            $rows[] = ["Label {$s} ex-VAT", $a['label_ex_vat'][$s]];
        }
        $rows[] = ['Label 5L/10L/20L, final', $a['label_large_final']];
        $rows[] = [];
        $rows[] = ['BAILING PAPER (KES/sheet)'];
        foreach ($a['bailing_rate_sheet'] as $s => $r) {
            $rows[] = ["Rate/sheet {$s}", $r, 'bottles/bale', $a['bottles_per_bale'][$s]];
        }
        $rows[] = [];
        $rows[] = ['SEALS'];
        foreach ($a['seal'] as $k => $v) {
            $rows[] = [$k, $v];
        }
        $rows[] = [];
        $rows[] = ['PRODUCTION WAGES (KES/bottle)'];
        foreach ($a['wage_per_bottle'] as $s => $v) {
            $rows[] = ["Wage {$s}", $v];
        }
        $rows[] = [];
        $rows[] = ['TRIP QUANTITIES'];
        foreach ($a['trip_bottles'] as $s => $v) {
            $rows[] = ["Bottles/trip {$s}", $v];
        }
        $rows[] = ['Transport cost/trip (Nairobi -> Rongo)', $a['transport_per_trip']];
        $rows[] = [];
        $rows[] = ['SELLING PRICES (application price list; per bale / unit)'];
        foreach ($snap['app_prices'] as $p) {
            $rows[] = [$p['name'], $p['price_per_unit'], $p['sold_as'], 'per piece', $p['price_per_piece']];
        }
        $rows[] = [];
        $rows[] = ['PAYROLL (monthly, KES)'];
        foreach ($a['payroll'] as $k => $v) {
            $rows[] = [$k, $v];
        }
        $rows[] = ['Planning basis', $a['payroll_basis']];
        foreach ($a['jim_stipend'] as $k => $v) {
            $rows[] = ["Jim's stipend {$k}", $v];
        }
        $rows[] = [];
        $rows[] = ['MONTHLY OVERHEADS'];
        foreach ($a['overheads'] as $k => $v) {
            $rows[] = [$k, $v];
        }
        $ws->fromArray($rows, null, 'A1');

        // Cost Build
        $cb = $book->createSheet();
        $cb->setTitle('Cost Build');
        $cb->fromArray(['Size', 'Bottle+cap', 'Label', 'Bailing paper', 'Seal', 'MATERIALS COST/bottle', 'Wage', 'Transport/bottle', 'COST OF PRODUCTION/bottle'], null, 'A1');
        $r = 2;
        foreach ($c['cost_build']['rows'] as $row) {
            $cb->fromArray([$row['size'], $row['bottle_cap'], $row['label'], $row['bailing_paper'], $row['seal'], $row['materials'], $row['wage'], $row['transport'], $row['cop']], null, "A{$r}");
            $r++;
        }
        $cb->setCellValue('A'.($r + 1), $c['cost_build']['note']);

        // SKU Margins
        $sm = $book->createSheet();
        $sm->setTitle('SKU Margins');
        $sm->fromArray(['SKU', 'Sold as', 'Bale/unit size', 'Cost/bottle', 'Price/bottle', 'Gross margin/btl', 'VAT/btl (16%, if registered)', 'Net margin/btl — VAT-registered', 'Net margin/btl — non-VAT (now)', 'Net % — non-VAT', 'Margin after full production cost'], null, 'A1');
        $r = 2;
        foreach ($c['sku_margins'] as $row) {
            $sm->fromArray([$row['sku'], $row['sold_as'], $row['bale_size'], $row['cost_per_bottle'], $row['price_per_bottle'], $row['gross_margin'], $row['vat_per_bottle'], $row['net_margin_vat'], $row['net_margin_non_vat'], $row['net_pct_non_vat'], $row['margin_after_full_cost']], null, "A{$r}");
            $r++;
        }

        // Trip P&L
        $tp = $book->createSheet();
        $tp->setTitle('Trip P&L');
        $tp->fromArray(['SKU', 'Qty', 'Bottle+cap', 'Label', 'Bailing paper', 'Seal', 'Wage', 'Transport/btl', 'COST/bottle', 'Price/bottle', 'Revenue', 'Cost total', 'Gross margin'], null, 'A1');
        $r = 2;
        foreach ($c['trip_pl']['a']['lines'] as $l) {
            $tp->fromArray([$l['sku'], $l['qty'], $l['bottle_cap'], $l['label'], $l['bailing_paper'], $l['seal'], $l['wage'], $l['transport'], $l['cost_per_bottle'], $l['price_per_bottle'], $l['revenue'], $l['cost_total'], $l['gross_margin']], null, "A{$r}");
            $r++;
        }
        $A = $c['trip_pl']['a'];
        $tp->fromArray(['BOTTLED, SUBTOTAL', $A['qty_total'], '', '', '', '', '', '', '', '', $A['revenue'], $A['cost_total'], $A['gross_margin']], null, "A{$r}");
        $r += 1;
        $tp->fromArray(['VAT payable, if registered', '', '', '', '', '', '', '', '', '', '', '', $A['vat_payable']], null, "A{$r}");
        $r += 1;
        $tp->fromArray(['Gross profit after VAT — VAT-registered', '', '', '', '', '', '', '', '', '', '', '', $A['gross_profit_after_vat']], null, "A{$r}");
        $r += 1;
        $tp->fromArray(['Gross profit — non-VAT, current reality', '', '', '', '', '', '', '', '', '', '', '', $A['gross_profit_non_vat']], null, "A{$r}");
        $r += 2;
        $tp->fromArray(['Line item', '1 trip', '2 trips', '3 trips', '4 trips', '5 trips'], null, "A{$r}");
        $r++;
        $lines = [
            ['Bottled revenue', 'bottled_revenue'], ['Total revenue', 'total_revenue'], ['Cost of production', 'cost_of_production'],
            ['Gross trading margin', 'gross_margin'], ['VAT payable, if registered', 'vat_payable'], ['Gross profit after VAT', 'gross_profit_after_vat'],
            ["Jim's stipend", 'jim_stipend'], ['Fixed payroll, LOW', 'fixed_payroll_low'], ['Fixed payroll, HIGH', 'fixed_payroll_high'], ['Overheads', 'overheads'],
            ['NET PROFIT — VAT, LOW payroll', 'net_vat_low'], ['NET PROFIT — VAT, HIGH payroll', 'net_vat_high'],
            ['NET PROFIT — non-VAT, LOW payroll', 'net_non_vat_low'], ['NET PROFIT — non-VAT, HIGH payroll', 'net_non_vat_high'],
        ];
        foreach ($lines as [$label, $key]) {
            $tp->fromArray(array_merge([$label], array_map(fn ($m) => $m[$key], $c['trip_pl']['b'])), null, "A{$r}");
            $r++;
        }

        // Profit Split
        $ps = $book->createSheet();
        $ps->setTitle('Profit Split');
        $P = $c['profit_split'];
        $r = 1;
        $ps->setCellValue("A{$r}", 'Basis: '.$P['basis_label']);
        $r += 2;
        foreach ([['A. Phase 1 monthly (Nov-Dec)', 'phase1_monthly'], ['B. Phase 1, 2-month cumulative', 'phase1_two_month'], ['D. Phase 2 (Jan 2027+) monthly', 'phase2_monthly']] as [$title, $key]) {
            $ps->setCellValue("A{$r}", $title);
            $r++;
            $ps->fromArray(['Partner', '1 trip', '2 trips', '3 trips', '4 trips', '5 trips'], null, "A{$r}");
            $r++;
            if ($key !== 'phase1_two_month') {
                $ps->fromArray(array_merge(['Net profit/month'], $P['net_profit']), null, "A{$r}");
                $r++;
            }
            foreach ($P[$key] as $name => $vals) {
                $ps->fromArray(array_merge([$name], $vals), null, "A{$r}");
                $r++;
            }
            $r++;
        }
        $ps->setCellValue("A{$r}", "C/E. Derrick's {$P['derrick']['total_committed']} capital — recovery");
        $r++;
        $ps->fromArray(array_merge(['Received Nov+Dec'], $P['derrick']['received_nov_dec']), null, "A{$r}");
        $r++;
        $ps->fromArray(array_merge(['% recovered'], $P['derrick']['pct_recovered']), null, "A{$r}");
        $r++;
        $ps->fromArray(array_merge(['Remaining after Nov+Dec'], $P['derrick']['remaining_after_nov_dec']), null, "A{$r}");
        $r++;
        $ps->fromArray(array_merge(['Months needed at Phase-2 rate'], array_map(fn ($v) => $v ?? 'n/a', $P['derrick']['months_needed_phase2'])), null, "A{$r}");

        // Funding & Startup
        $fs = $book->createSheet();
        $fs->setTitle('Funding & Startup');
        $F = $c['funding'];
        $r = 1;
        $fs->setCellValue("A{$r}", 'A. Total spend to date');
        $r++;
        $fs->fromArray(['Item', 'Full value', 'Due now / paid', 'Note'], null, "A{$r}");
        $r++;
        foreach ($F['spend_items'] as $row) {
            $fs->fromArray([$row['label'], $row['full'], $row['paid'], $row['note']], null, "A{$r}");
            $r++;
        }
        $fs->fromArray(['TOTAL', $F['spend_full'], $F['spend_paid']], null, "A{$r}");
        $r += 2;
        $fs->setCellValue("A{$r}", 'B. Loan');
        $r++;
        foreach ([['Principal', $F['loan']['principal']], ['Interest rate', $F['loan']['rate']], ['Interest amount', $F['loan']['interest']], ['TOTAL REPAYMENT', $F['loan']['total_repayment']], ['Repayment due', $F['loan']['due']]] as $x) {
            $fs->fromArray($x, null, "A{$r}");
            $r++;
        }
        $r++;
        $fs->setCellValue("A{$r}", 'C. Funding reconciliation');
        $r++;
        foreach ([['Partner capital received', $F['reconciliation']['capital_received']], ['Partner capital pending (Derrick)', $F['reconciliation']['capital_pending']], ['Loan', $F['reconciliation']['loan']], ['TOTAL FUNDING', $F['reconciliation']['total_funding']], ['less TOTAL SPEND', -$F['reconciliation']['total_spend']], ['BUFFER REMAINING', $F['reconciliation']['buffer']]] as $x) {
            $fs->fromArray($x, null, "A{$r}");
            $r++;
        }
        $r++;
        $fs->setCellValue("A{$r}", 'D. Bailing paper — 1 trip minus stock');
        $r++;
        $fs->fromArray(['SKU', 'Need (sheets)', 'On hand', 'Deficit', 'Bags needed', 'Cost (KES)'], null, "A{$r}");
        $r++;
        foreach ($F['bailing_paper']['rows'] as $row) {
            $fs->fromArray([$row['size'], $row['need_sheets'], $row['on_hand_sheets'], $row['deficit_sheets'], $row['bags_needed'], $row['cost']], null, "A{$r}");
            $r++;
        }
        $fs->fromArray(['TOTAL', '', '', '', '', $F['bailing_paper']['total']], null, "A{$r}");
        $r += 2;
        $fs->setCellValue("A{$r}", 'E. Bare-minimum cash to start');
        $r++;
        foreach ([['Bailing paper deficit', $F['startup']['bailing_deficit']], ['Buffer remaining', $F['startup']['buffer_remaining']], ['SHORTFALL / (surplus)', $F['startup']['shortfall_or_surplus']], ['1 month fixed cost, low payroll', $F['startup']['month_fixed_low']], ['1 month fixed cost, high payroll', $F['startup']['month_fixed_high']]] as $x) {
            $fs->fromArray($x, null, "A{$r}");
            $r++;
        }

        foreach ($book->getAllSheets() as $sheet) {
            $sheet->getColumnDimension('A')->setWidth(48);
        }

        $filename = 'mara-hypothesis-model-'.now('Africa/Nairobi')->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
