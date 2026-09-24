<?php

namespace App\Services;

use App\Models\DriverTrip;
use App\Models\DriverTripSaleItem;
use App\Models\MaterialBatch;
use App\Models\OrderItem;
use App\Models\PackagingRun;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\Warehouse;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Template-matching Excel exports for Mara Water / Homa Springs operations
 * (Inventory Control, Raw Materials, Warehouse stock cards, Refills,
 * Driver Work Sheet, HSL Sales Control). Production & Payroll already
 * have dedicated services.
 */
class OperationsExportService
{
    private array $headerFill;
    private array $bold;
    private array $thin;
    private array $title;

    public function __construct()
    {
        $this->headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $this->bold = ['font' => ['bold' => true]];
        $this->thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];
        $this->title = ['font' => ['bold' => true, 'size' => 14]];
    }

    /** Matches EXCELLS/INVENTORY CONTROL.xlsx — DAY/DATE/DETAIL STOCK+PRODUCTION rows. */
    public function inventoryControl(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle(strtoupper(Carbon::create($year, $month, 1)->format('F')));

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $ws->setCellValue('F1', 'INVENTORY CONTROL SHEET');
        $ws->getStyle('F1')->applyFromArray($this->title);

        $skus = Sku::where('active', true)->orderBy('brand')->orderBy('size_liters')->get();
        $byBrand = $skus->groupBy(fn ($s) => $s->brand ?: 'Other');

        $ws->setCellValue('A3', 'DAY');
        $ws->setCellValue('B3', 'DATE');
        $ws->setCellValue('C3', 'DETAIL');
        $ws->getStyle('A3:C3')->applyFromArray($this->headerFill + $this->thin);

        $col = 4;
        $skuCol = [];
        $brandRow = 2;
        foreach ($byBrand as $brand => $group) {
            $startCol = $col;
            $ws->setCellValue([$startCol, $brandRow], strtoupper($brand));
            $ws->getStyle([$startCol, $brandRow])->applyFromArray($this->bold);
            foreach ($group as $sku) {
                $skuCol[$sku->id] = $col;
                $label = $sku->size_liters ? (rtrim(rtrim(number_format((float) $sku->size_liters, 1), '0'), '.') . 'L') : $sku->name;
                $ws->setCellValue([$col, 3], $label);
                $ws->getStyle([$col, 3])->applyFromArray($this->headerFill + $this->thin);
                $col++;
            }
            if ($col - 1 > $startCol) {
                $ws->mergeCells([$startCol, $brandRow, $col - 1, $brandRow]);
            }
        }
        $lastCol = max(3, $col - 1);

        $produced = PackagingRun::whereNotNull('run_end')
            ->whereBetween('run_end', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->selectRaw('DATE(run_end) as day, sku_id, SUM(good_qty) as qty')
            ->groupBy('day', 'sku_id')->get();
        $prodByDay = [];
        foreach ($produced as $r) {
            $prodByDay[$r->day][$r->sku_id] = (float) $r->qty;
        }

        // Opening stock = on-hand today rolled back is hard; use current
        // StockItem as end-of-period proxy for opening of first day when
        // no historical card exists — production rows still exact.
        $opening = StockItem::where('item_type', 'sku')->get()
            ->groupBy('sku_id')->map(fn ($g) => (float) $g->sum('qty'));

        $row = 4;
        $cursor = $start->copy();
        $running = $opening->all();
        $first = true;
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            // STOCK row
            $ws->setCellValue("A{$row}", strtoupper($cursor->format('D')));
            $ws->setCellValue("B{$row}", $day);
            $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $ws->setCellValue("C{$row}", 'STOCK');
            foreach ($skuCol as $skuId => $c) {
                $ws->setCellValue([$c, $row], $running[$skuId] ?? 0);
            }
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($this->thin);
            }
            $stockRow = $row;
            $row++;

            // PRODUCTION row
            $ws->setCellValue("C{$row}", 'PRODUCTION');
            foreach ($skuCol as $skuId => $c) {
                $qty = $prodByDay[$day][$skuId] ?? 0;
                if ($qty) {
                    $ws->setCellValue([$c, $row], $qty);
                }
                // Next day's opening ≈ stock − production (simplified control sheet)
                $running[$skuId] = ($running[$skuId] ?? 0) - $qty;
            }
            for ($c = 1; $c <= $lastCol; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($this->thin);
            }
            unset($first, $stockRow);
            $row++;
            $cursor->addDay();
        }

        foreach (range(1, $lastCol) as $c) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        return $this->stream($spreadsheet, sprintf('inventory-control-%04d-%02d.xlsx', $year, $month));
    }

    /** Matches EXCELLS/RAW MATERIAL REAL.xlsx — bottle sizes, opening + daily usage. */
    public function rawMaterials(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Raw Materials');

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $ws->setCellValue('E1', 'RAW MATERIALS USAGE');
        $ws->getStyle('E1')->applyFromArray($this->title);
        $ws->setCellValue('E2', 'EMPTY BOTTLES (BAGS) — FineLine / Blowplast → Rongo');
        $ws->setCellValue('E3', 'Transport consignment cost (KES): ' . number_format((float) config('mara_operations.bottle_transport_cost_kes', 45000), 0));

        $headers = ['DATE', 'SUPPLIER', 'BATCH', 'BAGS IN', 'UNIT COST', 'TRANSPORT', 'NOTES'];
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 5], $h);
            $ws->getStyle([$i + 1, 5])->applyFromArray($this->headerFill + $this->thin);
        }

        $batches = MaterialBatch::with('material:id,name')
            ->whereBetween('purchase_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('purchase_date')
            ->get();

        $row = 6;
        foreach ($batches as $b) {
            $ws->setCellValue("A{$row}", $b->purchase_date?->toDateString());
            $ws->setCellValue("B{$row}", $b->supplier_name ?: ($b->supplier_code ?? ''));
            $ws->setCellValue("C{$row}", $b->batch_number);
            $ws->setCellValue("D{$row}", (float) $b->qty_received);
            $ws->setCellValue("E{$row}", (float) $b->unit_cost);
            $ws->setCellValue("F{$row}", $b->transport_cost !== null ? (float) $b->transport_cost : null);
            $ws->setCellValue("G{$row}", trim(($b->material->name ?? '') . ' | bags | ' . ($b->notes ?? '')));
            for ($c = 1; $c <= 7; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($this->thin);
            }
            $row++;
        }

        if ($batches->isEmpty()) {
            $ws->setCellValue("A{$row}", 'No bag consignments recorded this month. Receive FineLine/Blowplast lots on Inventory → Materials.');
        }

        foreach (range('A', 'G') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $this->stream($spreadsheet, sprintf('raw-materials-%04d-%02d.xlsx', $year, $month));
    }

    /** Matches MAIN STOCK WARE HOUSE.xlsx — DATE / OPENING / IN / OUT / RETURNS / BALANCE. */
    public function warehouseStockCards(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $warehouse = Warehouse::orderBy('name')->first();

        $skus = Sku::where('active', true)->orderBy('brand')->orderBy('name')->limit(8)->get();
        $sheetIndex = 0;
        foreach ($skus as $i => $sku) {
            if ($i === 0) {
                $ws = $spreadsheet->getActiveSheet();
            } else {
                $ws = $spreadsheet->createSheet();
            }
            $ws->setTitle(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $sku->name) ?: 'SKU', 0, 28));
            $sheetIndex++;

            $ws->setCellValue('B1', 'STOCK CARD WAREHOUSE');
            $ws->getStyle('B1')->applyFromArray($this->title);
            $ws->setCellValue('B2', $sku->name);
            $ws->setCellValue('D2', $sku->brand);

            foreach (['DATE', 'OPENING BALANCES', 'STOCK IN', 'STOCK OUT', 'RETURNS', 'BALANCE'] as $ci => $h) {
                $ws->setCellValue([$ci + 1, 3], $h);
                $ws->getStyle([$ci + 1, 3])->applyFromArray($this->headerFill + $this->thin);
            }

            if (!$warehouse) {
                $ws->setCellValue('A4', 'No warehouse configured');
                continue;
            }

            $openingIn = (float) StockMove::whereNull('deleted_at')->where('item_type', 'sku')->where('sku_id', $sku->id)
                ->where('warehouse_to_id', $warehouse->id)->where('moved_at', '<', $start->toDateString())->sum('qty');
            $openingOut = (float) StockMove::whereNull('deleted_at')->where('item_type', 'sku')->where('sku_id', $sku->id)
                ->where('warehouse_from_id', $warehouse->id)->where('moved_at', '<', $start->toDateString())->sum('qty');
            $running = $openingIn - $openingOut;

            $moves = StockMove::whereNull('deleted_at')->where('item_type', 'sku')->where('sku_id', $sku->id)
                ->where(function ($q) use ($warehouse) {
                    $q->where('warehouse_to_id', $warehouse->id)->orWhere('warehouse_from_id', $warehouse->id);
                })
                ->whereBetween('moved_at', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                ->orderBy('moved_at')->get();

            $byDay = [];
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $byDay[$cursor->toDateString()] = ['in' => 0.0, 'out' => 0.0, 'returns' => 0.0];
                $cursor->addDay();
            }
            foreach ($moves as $m) {
                $day = Carbon::parse($m->moved_at)->toDateString();
                if (!isset($byDay[$day])) {
                    continue;
                }
                $qty = (float) $m->qty;
                if ($m->warehouse_to_id === $warehouse->id) {
                    if ($m->move_type === 'return') {
                        $byDay[$day]['returns'] += $qty;
                    } else {
                        $byDay[$day]['in'] += $qty;
                    }
                }
                if ($m->warehouse_from_id === $warehouse->id) {
                    $byDay[$day]['out'] += $qty;
                }
            }

            $row = 4;
            foreach ($byDay as $day => $vals) {
                $ws->setCellValue("A{$row}", $day);
                $ws->setCellValue("B{$row}", $running);
                $ws->setCellValue("C{$row}", $vals['in']);
                $ws->setCellValue("D{$row}", $vals['out']);
                $ws->setCellValue("E{$row}", $vals['returns']);
                $balance = $running + $vals['in'] - $vals['out'] + $vals['returns'];
                $ws->setCellValue("F{$row}", $balance);
                for ($c = 1; $c <= 6; $c++) {
                    $ws->getStyle([$c, $row])->applyFromArray($this->thin);
                }
                $running = $balance;
                $row++;
            }
            foreach (range('A', 'F') as $c) {
                $ws->getColumnDimension($c)->setAutoSize(true);
            }
        }

        return $this->stream($spreadsheet, sprintf('main-stock-warehouse-%04d-%02d.xlsx', $year, $month));
    }

    /** Matches REFILS.xlsx — daily refill quantities by large sizes. */
    public function refills(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $ws->setTitle(strtoupper($start->format('M')));
        $ws->setCellValue('C1', 'REFILS ' . strtoupper($start->format('F Y')));
        $ws->getStyle('C1')->applyFromArray($this->title);

        $refillSkus = Sku::where('active', true)
            ->where(function ($q) {
                $q->where('brand', 'like', '%Refill%')
                    ->orWhere('name', 'like', '%refill%')
                    ->orWhereIn('size_liters', [5, 10, 20]);
            })
            ->orderBy('size_liters', 'desc')
            ->get();

        if ($refillSkus->isEmpty()) {
            $refillSkus = Sku::where('active', true)->whereIn('size_liters', [5, 10, 20])->orderByDesc('size_liters')->get();
        }

        $ws->setCellValue('A2', 'DATE');
        $ws->getStyle('A2')->applyFromArray($this->headerFill + $this->thin);
        $col = 2;
        $skuCol = [];
        foreach ($refillSkus as $sku) {
            $skuCol[$sku->id] = $col;
            $label = $sku->size_liters ? ((int) $sku->size_liters) . 'L' : $sku->name;
            $ws->setCellValue([$col, 2], $label);
            $ws->getStyle([$col, 2])->applyFromArray($this->headerFill + $this->thin);
            $col++;
        }

        // Prefer in-house order lines; fall back to trip sale items for refill brands
        $orderLines = OrderItem::query()
            ->whereIn('sku_id', $refillSkus->pluck('id'))
            ->whereHas('order', function ($q) use ($start, $end) {
                $q->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
                    ->whereNull('deleted_at');
            })
            ->with('order:id,order_date')
            ->get();

        $byDay = [];
        foreach ($orderLines as $line) {
            $day = optional($line->order)->order_date;
            if (!$day) {
                continue;
            }
            $d = Carbon::parse($day)->toDateString();
            $byDay[$d][$line->sku_id] = ($byDay[$d][$line->sku_id] ?? 0) + (float) ($line->qty ?? $line->quantity ?? 0);
        }

        $row = 3;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $d = $cursor->toDateString();
            $ws->setCellValue("A{$row}", $d);
            foreach ($skuCol as $skuId => $c) {
                $ws->setCellValue([$c, $row], $byDay[$d][$skuId] ?? 0);
            }
            $row++;
            $cursor->addDay();
        }

        return $this->stream($spreadsheet, sprintf('refills-%04d-%02d.xlsx', $year, $month));
    }

    /** Matches DRIVER WORK SHEET — mileage, fuel, route, time out. */
    public function driverWorksheet(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $ws->setTitle('Driver Work Sheet');
        $ws->setCellValue('D1', 'DRIVER WORK SHEET ' . strtoupper($start->format('F Y')));
        $ws->getStyle('D1')->applyFromArray($this->title);

        $headers = ['DATE', 'NAME', 'NUMBER', 'ROUTE', 'MILAGE START', 'MILAGE END', 'FUEL DRAWN', 'OIL', 'KM', 'AUTHORIZE OFFICER', 'TIME OUT'];
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 2], $h);
            $ws->getStyle([$i + 1, 2])->applyFromArray($this->headerFill + $this->thin);
        }

        $trips = DriverTrip::with(['driver:id,first_name,last_name,phone', 'authorizingOfficer:id,first_name,last_name'])
            ->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])
            ->whereNull('deleted_at')
            ->orderBy('trip_date')
            ->get();

        $row = 3;
        foreach ($trips as $t) {
            $officer = $t->authorizingOfficer
                ? trim(($t->authorizingOfficer->first_name ?? '') . ' ' . ($t->authorizingOfficer->last_name ?? ''))
                : '';
            $ws->setCellValue("A{$row}", $t->trip_date?->toDateString() ?? $t->trip_date);
            $ws->setCellValue("B{$row}", trim(($t->driver->first_name ?? '') . ' ' . ($t->driver->last_name ?? '')));
            $ws->setCellValue("C{$row}", $t->driver->phone ?? '');
            $ws->setCellValue("D{$row}", $t->route ?? '');
            $ws->setCellValue("E{$row}", $t->mileage_start);
            $ws->setCellValue("F{$row}", $t->mileage_end);
            $ws->setCellValue("G{$row}", $t->fuel_liters);
            $ws->setCellValue("H{$row}", null);
            $ws->setCellValue("I{$row}", $t->km_covered);
            $ws->setCellValue("J{$row}", $officer);
            $ws->setCellValue("K{$row}", $t->time_out);
            for ($c = 1; $c <= 11; $c++) {
                $ws->getStyle([$c, $row])->applyFromArray($this->thin);
            }
            $row++;
        }

        foreach (range('A', 'K') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $this->stream($spreadsheet, sprintf('driver-worksheet-%04d-%02d.xlsx', $year, $month));
    }

    /**
     * HSL Sales Control style: dispatched vs returned (returned = dispatched − sold).
     */
    public function salesControl(int $year, int $month): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $ws->setTitle('Sales Control');

        $ws->setCellValue('A2', 'HOMA SPRINGS LIMITED / MARA WATER');
        $ws->getStyle('A2')->applyFromArray($this->title);
        $ws->setCellValue('A3', 'SALES CONTROL TOOL ' . strtoupper($start->format('F Y')) . ' — Rongo operations');
        $ws->setCellValue('A4', 'QUANTITY DISPATCHED');
        $ws->setCellValue('H4', 'QUANTITY RETURNED (dispatched − sold)');
        $ws->getStyle('A4')->applyFromArray($this->bold);
        $ws->getStyle('H4')->applyFromArray($this->bold);

        $headers = ['DATE', 'PRODUCT', 'LITRES', 'QTY DISPATCHED', 'UNIT PRICE', 'AMOUNT', '', 'QTY RETURNED', 'RETURN AMOUNT'];
        foreach ($headers as $i => $h) {
            if ($h === '') {
                continue;
            }
            $col = $i + 1;
            $ws->setCellValue([$col, 5], $h);
            $ws->getStyle([$col, 5])->applyFromArray($this->headerFill + $this->thin);
        }

        $trips = DriverTrip::with(['items.sku', 'sales.items'])
            ->whereBetween('trip_date', [$start->toDateString(), $end->toDateString()])
            ->whereNull('deleted_at')
            ->orderBy('trip_date')
            ->get();

        $row = 6;
        foreach ($trips as $trip) {
            $soldBySku = [];
            foreach ($trip->sales as $sale) {
                foreach ($sale->items as $li) {
                    $soldBySku[$li->sku_id] = ($soldBySku[$li->sku_id] ?? 0) + (float) $li->qty_bales;
                }
            }
            foreach ($trip->items as $item) {
                $sku = $item->sku;
                $dispatched = (float) $item->qty_carried_bales;
                $sold = (float) ($soldBySku[$item->sku_id] ?? 0);
                $returned = max(0, $dispatched - $sold);
                $price = (float) ($item->unit_price ?? 0);
                $ws->setCellValue("A{$row}", $trip->trip_date?->toDateString());
                $ws->setCellValue("B{$row}", $sku->brand ?? $sku->name ?? '');
                $ws->setCellValue("C{$row}", $sku->size_liters ? ($sku->size_liters . ' L') : ($sku->name ?? ''));
                $ws->setCellValue("D{$row}", $dispatched);
                $ws->setCellValue("E{$row}", $price);
                $ws->setCellValue("F{$row}", round($dispatched * $price, 2));
                $ws->setCellValue("H{$row}", $returned);
                $ws->setCellValue("I{$row}", round($returned * $price, 2));
                foreach ([1, 2, 3, 4, 5, 6, 8, 9] as $c) {
                    $ws->getStyle([$c, $row])->applyFromArray($this->thin);
                }
                $row++;
            }
        }

        $row += 2;
        $ws->setCellValue("A{$row}", 'Note: Returns are auto-computed as dispatched bales minus sold bales from the driver dashboard.');
        $ws->mergeCells("A{$row}:I{$row}");

        foreach (range('A', 'I') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        return $this->stream($spreadsheet, sprintf('hsl-sales-control-%04d-%02d.xlsx', $year, $month));
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
