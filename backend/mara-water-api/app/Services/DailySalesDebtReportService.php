<?php

namespace App\Services;

use App\Models\Discrepancy;
use App\Models\DriverTripItem;
use App\Models\DriverTripSale;
use App\Models\Location;
use App\Models\Order;
use App\Models\Sku;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 3 Phase 9: "DAILY SALES AND DEBT REPORT [MONTH]" -- reproduces
 * the real layout confirmed in the October/December sales summaries:
 * date rows down the left, column groups split by location (KDN/KDQ/
 * WAREHOUSE), a GROSS TOTAL row, and a separate Bales Sold section
 * broken out by brand/size per location.
 *
 * Still narrower than the source file's column set: nothing in this app
 * tracks a per-sale discount or a daily cash-banking reconciliation, so
 * Discount and Banked Amount would just be permanently blank/zero here
 * and are omitted. TOTAL SALES / DEBT / DISTRIBUTOR (from
 * Customer::type='wholesale' -- the closest real equivalent) / EXP CASH
 * (= sales - debt, the one derived figure that's actually computable),
 * each split KDN/KDQ/WAREHOUSE in that consistent order (the source
 * file's own column order varied month to month; this doesn't
 * perpetuate that).
 *
 * Round 4 Phase 11: DIFFERENCE is back, now that Phase 6's discrepancies
 * table gives it a real data source -- the sum of that day/location's
 * 'cash' category Discrepancy rows (the ones DriverTripController::
 * end()'s defensive revenue self-consistency check raises). Stock/
 * mileage discrepancies aren't money, so they don't feed this column;
 * this is deliberately the same number the Discrepancies page would
 * show for cash issues on that day, so the two never drift into two
 * sources of truth for the same figure.
 *
 * Bales Sold sources only from driver_trip_items (net dispatched --
 * carried minus returned bales), not order_items, since bales are a
 * driver-trip-specific unit (Round 3 Phase 2) with no bottle-level
 * equivalent on counter Orders.
 */
class DailySalesDebtReportService
{
    public function generate(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Daily Sales & Debt');

        $locations = Location::orderByRaw("FIELD(code, 'KDN', 'KDQ', 'WAREHOUSE')")->get();
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        $bold = ['font' => ['bold' => true]];
        $title = ['font' => ['bold' => true, 'size' => 14]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true], 'alignment' => ['horizontal' => 'center']];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $row = 1;
        $ws->setCellValue("A{$row}", 'DAILY SALES AND DEBT REPORT ' . strtoupper($monthStart->format('F Y')));
        $ws->getStyle("A{$row}")->applyFromArray($title);
        $row += 2;

        // ---- Section 1: Daily Sales & Debt table ----
        $groupHeaderRow = $row;
        $subHeaderRow = $row + 1;
        $col = 2; // B -- column A is DATE
        $groups = ['TOTAL SALES', 'DEBT', 'DISTRIBUTOR', 'EXP CASH', 'DIFFERENCE'];
        $groupStartCol = [];
        $ws->setCellValue([1, $groupHeaderRow], 'DATE');
        $ws->getStyle([1, $groupHeaderRow])->applyFromArray($headerFill + $thin);
        foreach ($groups as $group) {
            $groupStartCol[$group] = $col;
            $ws->setCellValue([$col, $groupHeaderRow], $group);
            $ws->mergeCells([$col, $groupHeaderRow, $col + 2, $groupHeaderRow]);
            $ws->getStyle([$col, $groupHeaderRow])->applyFromArray($headerFill + $thin);
            foreach ($locations as $i => $loc) {
                $ws->setCellValue([$col + $i, $subHeaderRow], $loc->code);
                $ws->getStyle([$col + $i, $subHeaderRow])->applyFromArray($headerFill + $thin);
            }
            $col += 3;
        }
        $lastDataCol = $col - 1;
        $row = $subHeaderRow + 1;
        $firstDataRow = $row;

        // Pre-aggregate per day/location: sales (all payment methods),
        // debt-only, and distributor (wholesale-type customer) sales.
        $ordersByDayLoc = $this->orderTotalsByDayLocation($monthStart, $monthEnd);
        $tripsByDayLoc = $this->tripTotalsByDayLocation($monthStart, $monthEnd);
        $cashDiscrepancyByDayLoc = $this->cashDiscrepancyByDayLocation($monthStart, $monthEnd);

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $monthStart->copy()->day($d)->toDateString();
            $ws->setCellValue([1, $row], $date);
            $ws->getStyle([1, $row])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            foreach ($locations as $i => $loc) {
                $o = $ordersByDayLoc->get($date . '|' . $loc->id, ['sales' => 0, 'debt' => 0, 'distributor' => 0]);
                $t = $tripsByDayLoc->get($date . '|' . $loc->id, ['sales' => 0, 'debt' => 0, 'distributor' => 0]);
                $sales = $o['sales'] + $t['sales'];
                $debt = $o['debt'] + $t['debt'];
                $distributor = $o['distributor'] + $t['distributor'];
                $expCash = $sales - $debt;
                $difference = $cashDiscrepancyByDayLoc->get($date . '|' . $loc->id, 0);

                $ws->setCellValue([$groupStartCol['TOTAL SALES'] + $i, $row], $sales);
                $ws->setCellValue([$groupStartCol['DEBT'] + $i, $row], $debt);
                $ws->setCellValue([$groupStartCol['DISTRIBUTOR'] + $i, $row], $distributor);
                $ws->setCellValue([$groupStartCol['EXP CASH'] + $i, $row], $expCash);
                $ws->setCellValue([$groupStartCol['DIFFERENCE'] + $i, $row], $difference);
            }
            for ($c = 1; $c <= $lastDataCol; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($thin);
            }
            $row++;
        }

        $lastDataRow = $row - 1;
        $ws->setCellValue([1, $row], 'GROSS TOTAL');
        $ws->getStyle([1, $row])->applyFromArray($bold);
        for ($c = 2; $c <= $lastDataCol; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $ws->setCellValue([$c, $row], "=SUM({$colLetter}{$firstDataRow}:{$colLetter}{$lastDataRow})");
            $ws->getStyle([$c, $row])->applyFromArray($bold);
        }
        $row += 3;

        // ---- Section 2: Bales Sold, per location ----
        $ws->setCellValue([1, $row], 'BALES SOLD');
        $ws->getStyle([1, $row])->applyFromArray($title);
        $row += 2;

        $skuGroups = $this->skusByBrand();
        $balesByDayLocSku = $this->netBalesByDayLocationSku($monthStart, $monthEnd);

        foreach ($locations as $loc) {
            $ws->setCellValue([1, $row], $loc->code);
            $ws->getStyle([1, $row])->applyFromArray($bold);
            $row++;

            $brandHeaderRow = $row;
            $sizeHeaderRow = $row + 1;
            $col = 2;
            $skuCol = []; // sku_id => column index
            $ws->setCellValue([1, $brandHeaderRow], 'DATE:');
            $ws->getStyle([1, $brandHeaderRow])->applyFromArray($headerFill + $thin);
            foreach ($skuGroups as $brand => $skus) {
                $startCol = $col;
                foreach ($skus as $sku) {
                    $skuCol[$sku->id] = $col;
                    $ws->setCellValue([$col, $sizeHeaderRow], $sku->name);
                    $ws->getStyle([$col, $sizeHeaderRow])->applyFromArray($headerFill + $thin);
                    $col++;
                }
                if ($col - 1 >= $startCol) {
                    $ws->setCellValue([$startCol, $brandHeaderRow], $brand);
                    $ws->mergeCells([$startCol, $brandHeaderRow, $col - 1, $brandHeaderRow]);
                    $ws->getStyle([$startCol, $brandHeaderRow])->applyFromArray($headerFill + $thin);
                }
            }
            $lastBalesCol = $col - 1;
            $row = $sizeHeaderRow + 1;
            $balesFirstRow = $row;

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = $monthStart->copy()->day($d)->toDateString();
                $ws->setCellValue([1, $row], $date);
                $ws->getStyle([1, $row])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                foreach ($skuCol as $skuId => $c) {
                    $qty = $balesByDayLocSku->get($date . '|' . $loc->id . '|' . $skuId, 0);
                    $ws->setCellValue([$c, $row], $qty);
                    $ws->getStyle([$c, $row])->applyFromArray($thin);
                }
                $row++;
            }
            $balesLastRow = $row - 1;

            $ws->setCellValue([1, $row], 'TOTAL');
            $ws->getStyle([1, $row])->applyFromArray($bold);
            foreach ($skuCol as $skuId => $c) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $ws->setCellValue([$c, $row], "=SUM({$colLetter}{$balesFirstRow}:{$colLetter}{$balesLastRow})");
                $ws->getStyle([$c, $row])->applyFromArray($bold);
            }
            $row += 3;
        }

        foreach (range('A', 'Z') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "daily-sales-debt-{$monthStart->format('Y-m')}.xlsx";
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** date|location_id => ['sales'=>, 'debt'=>, 'distributor'=>] from Orders. */
    private function orderTotalsByDayLocation(Carbon $from, Carbon $to): Collection
    {
        $rows = Order::completedSale()->whereNull('deleted_at')
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->with('customer')
            ->get();

        $out = collect();
        foreach ($rows as $o) {
            if (!$o->location_id) {
                continue; // unattributed -- excluded from the location split, not guessed
            }
            $key = $o->order_date->toDateString() . '|' . $o->location_id;
            $bucket = $out->get($key, ['sales' => 0, 'debt' => 0, 'distributor' => 0]);
            $bucket['sales'] += (float) $o->total_amount;
            if ($o->payment_method === 'credit') {
                $bucket['debt'] += (float) $o->total_amount;
            }
            if (optional($o->customer)->type === 'wholesale') {
                $bucket['distributor'] += (float) $o->total_amount;
            }
            $out->put($key, $bucket);
        }
        return $out;
    }

    /** Same shape as above, from DriverTripSale via the trip's location. */
    private function tripTotalsByDayLocation(Carbon $from, Carbon $to): Collection
    {
        $rows = DriverTripSale::whereNull('deleted_at')
            ->whereHas('trip', fn ($q) => $q->whereBetween('trip_date', [$from->toDateString(), $to->toDateString()])->whereNotNull('location_id'))
            ->with(['trip', 'customer'])
            ->get();

        $out = collect();
        foreach ($rows as $s) {
            $trip = $s->trip;
            if (!$trip || !$trip->location_id) {
                continue;
            }
            $key = $trip->trip_date->toDateString() . '|' . $trip->location_id;
            $bucket = $out->get($key, ['sales' => 0, 'debt' => 0, 'distributor' => 0]);
            $bucket['sales'] += (float) $s->amount;
            if ($s->payment_method === 'debt') {
                $bucket['debt'] += (float) $s->amount;
            }
            if (optional($s->customer)->type === 'wholesale') {
                $bucket['distributor'] += (float) $s->amount;
            }
            $out->put($key, $bucket);
        }
        return $out;
    }

    /**
     * date|location_id => sum of that day/location's 'cash' category
     * Discrepancy amounts. Joined via the flagged trip's location_id --
     * mileage-only discrepancies (no driver_trip_id, e.g. none currently,
     * but defensively excluded) don't have a location to attribute to a
     * day/location cell and are excluded rather than guessed.
     */
    private function cashDiscrepancyByDayLocation(Carbon $from, Carbon $to): Collection
    {
        $rows = Discrepancy::where('category', 'cash')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('trip', fn ($q) => $q->whereNotNull('location_id'))
            ->with('trip')
            ->get();

        $out = collect();
        foreach ($rows as $d) {
            $locationId = $d->trip->location_id ?? null;
            if (!$locationId) {
                continue;
            }
            $key = $d->date->toDateString() . '|' . $locationId;
            $out->put($key, ($out->get($key, 0)) + (float) $d->amount);
        }
        return $out;
    }

    /**
     * date|location_id|sku_id => net dispatched bales (carried -
     * returned). qty_carried_bales/qty_returned_bales are both `int
     * unsigned` -- subtracting two unsigned columns directly makes MySQL
     * evaluate the difference as unsigned too, so any row where returned
     * legitimately exceeds carried (bad historical data entered before
     * Round 4 Phase 2 made Returned a computed, not typed, field --
     * confirmed live: one pre-Round-4 trip has 677 returned against only
     * 9 carried) wraps around to a huge positive number instead of going
     * negative, and SUM() then overflows BIGINT UNSIGNED and 500s the
     * whole export. Casting to SIGNED first fixes the arithmetic for
     * every month, not just ones without bad historical rows.
     */
    private function netBalesByDayLocationSku(Carbon $from, Carbon $to): Collection
    {
        $rows = DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
            ->whereNotNull('driver_trips.location_id')
            ->whereBetween('driver_trips.trip_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('driver_trips.trip_date, driver_trips.location_id, driver_trip_items.sku_id,
                SUM(CAST(driver_trip_items.qty_carried_bales AS SIGNED) - CAST(driver_trip_items.qty_returned_bales AS SIGNED)) as net_bales')
            ->groupBy('driver_trips.trip_date', 'driver_trips.location_id', 'driver_trip_items.sku_id')
            ->get();

        $out = collect();
        foreach ($rows as $r) {
            $date = \Carbon\Carbon::parse($r->trip_date)->toDateString();
            $out->put($date . '|' . $r->location_id . '|' . $r->sku_id, (int) $r->net_bales);
        }
        return $out;
    }

    /** Active SKUs grouped by brand, in a stable preferred order. */
    private function skusByBrand(): Collection
    {
        $order = ['Premium', 'Platinum', 'Grace', 'Refill'];
        $skus = Sku::where('active', true)->orderBy('name')->get();
        return $skus->groupBy(fn ($s) => $s->brand ?: 'Uncategorized')
            ->sortBy(fn ($group, $brand) => in_array($brand, $order) ? array_search($brand, $order) : 99);
    }
}
