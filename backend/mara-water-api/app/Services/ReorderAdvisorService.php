<?php

namespace App\Services;

use App\Models\DriverTrip;
use App\Models\PettyCashEntry;
use App\Models\SupplyOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-only reorder + cash-trigger advisor for Operations.
 *
 * For every stocked input (bottles, caps, labels, bailing paper, seals) it
 *  - forecasts consumption from sales (and production) history with a
 *    plan-prior blend (see DemandForecaster),
 *  - computes days of cover, stock-out date, reorder point (lead time +
 *    safety stock) and an MOQ-aware suggested order,
 *  - counts open supply orders as "on order" so alerts do not re-fire,
 * and it tracks cash against the cost of the next Nairobi bottle trip so
 * the order is placed the moment sales can fund it, while warning when that
 * would leave the month's fixed costs uncovered.
 */
class ReorderAdvisorService
{
    private const WINDOW_DAYS = 28;

    public function __construct(
        private readonly OperationsModelService $model,
        private readonly SalesRevenueService $revenue,
    ) {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $a = $this->model->effective();
        $sup = $a['supply'];
        $today = Carbon::now()->startOfDay();
        $vf = 1 + (float) $a['vat_rate'];
        $z = (float) $sup['service_level_z'];
        $safety = (int) $sup['safety_days'];
        $cover = (int) $sup['target_cover_days'];

        // ── Demand & production history (bottles/day by size) ────────
        $from = $today->copy()->subDays(self::WINDOW_DAYS - 1);
        $dates = [];
        for ($d = $from->copy(); $d->lte($today); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $sales = $this->bottlesByDaySize('sales', $from->toDateString(), $today->toDateString(), $a);
        $prod = $this->bottlesByDaySize('production', $from->toDateString(), $today->toDateString(), $a);

        $opDays = max(1, (int) $sup['operating_days_per_month']);
        $trips = max(0.0, (float) $sup['trips_per_month']);
        $dow = (int) $today->format('w');

        $forecast = [];
        $totalSeries = array_fill(0, count($dates), 0.0);
        foreach (OperationsModelService::SIZES as $size) {
            $series = [];
            foreach ($dates as $i => $dt) {
                $v = (float) ($sales[$dt][$size] ?? 0);
                $series[] = $v;
                $totalSeries[$i] += $v;
            }
            $prior = in_array($size, OperationsModelService::BOTTLED, true)
                ? $trips * (float) ($a['trip_bottles'][$size] ?? 0) / $opDays : 0.0;
            $f = DemandForecaster::forecast($series, $prior, $dow);
            $prodDaily = $this->avgDaily($prod, $size, $dates);
            $f['production_daily'] = $prodDaily['avg'];
            $f['production_window_days'] = $prodDaily['days'];
            $f['sold_28d'] = array_sum($series);
            // Production is a floor only when there is >= 2 weeks of production history.
            $f['consumption_daily'] = $prodDaily['days'] >= 14
                ? max($f['forecast_daily'], $prodDaily['avg']) : $f['forecast_daily'];
            $forecast[$size] = $f;
        }

        // ── Stock & open orders ──────────────────────────────────────
        $codes = [];
        foreach (OperationsModelService::SIZES as $s) {
            array_push($codes, "BOTTLE-$s", "LABEL-$s", "BAILING-$s");
        }
        array_push($codes, 'CAP-28MM-DARK', 'SEAL-BOTTLE');
        $stock = $this->materialStock($codes);
        $onOrder = $this->openOrderQty();

        // ── Item advice ──────────────────────────────────────────────
        $leadBd = $sup['lead_business_days'];
        $items = [];
        $bottledSum = fn () => array_sum(array_map(fn ($s) => $forecast[$s]['consumption_daily'], OperationsModelService::BOTTLED));
        $sigmaBottled = sqrt(array_sum(array_map(fn ($s) => $forecast[$s]['sigma'] ** 2, OperationsModelService::BOTTLED)));
        $upTo5 = ['0.5L', '1L', '1.5L', '5L'];

        $make = function (string $group, string $label, string $code, string $size, float $daily, float $sigma, int $leadBusiness, float $moq, float $step, float $unitCostIncl, string $unit, array $extra = []) use (&$items, $stock, $onOrder, $today, $safety, $z, $cover) {
            $items[] = $this->adviseItem($group, $label, $code, $size, $daily, $sigma, $leadBusiness, $moq, $step, $unitCostIncl, $unit,
                (float) ($stock[$code] ?? 0), (float) ($onOrder[$code] ?? 0), $today, $safety, $z, $cover, $extra);
        };

        foreach (OperationsModelService::SIZES as $size) {
            $f = $forecast[$size];
            $active = in_array($size, OperationsModelService::BOTTLED, true) || $f['consumption_daily'] > 0 || ($stock["BOTTLE-$size"] ?? 0) > 0;
            if (! $active) {
                continue;
            }
            $bottled = in_array($size, OperationsModelService::BOTTLED, true);
            $bottleUnit = $bottled
                ? ((float) $a['bottle_ex_vat'][$size] + (float) $a['cap_ex_vat']) * $vf
                : (float) ($a['bottle_cap_final'][$size] ?? 0);
            $make('bottles', "Bottles {$size}".($bottled ? ' + cap' : ''), "BOTTLE-$size", $size, $f['consumption_daily'], $f['sigma'],
                (int) $leadBd['bottles'], 0, 100, $bottleUnit, 'pcs', ['trip_item' => true, 'vat_applies' => true, 'supplier' => $bottled ? $a['suppliers']['bottles'] : $a['suppliers']['large_bottles']]);
        }
        $make('bottles', 'Caps 28mm (0.5L / 1L / 1.5L)', 'CAP-28MM-DARK', 'shared', $bottledSum(), $sigmaBottled,
            (int) $leadBd['bottles'], 0, 100, (float) $a['cap_ex_vat'] * $vf, 'pcs', ['trip_item' => true, 'vat_applies' => true, 'supplier' => $a['suppliers']['bottles']]);

        foreach (OperationsModelService::SIZES as $size) {
            $f = $forecast[$size];
            $bottled = in_array($size, OperationsModelService::BOTTLED, true);
            if (! $bottled && $f['consumption_daily'] <= 0 && ($stock["LABEL-$size"] ?? 0) <= 0) {
                continue;
            }
            $unit = $bottled ? (float) $a['label_ex_vat'][$size] * $vf : (float) $a['label_large_final'];
            $moq = (float) ($sup['moq']["label_$size"] ?? 0);
            $make('labels', "Labels {$size}", "LABEL-$size", $size, $f['consumption_daily'], $f['sigma'],
                (int) $leadBd['labels'], $moq, 1000, $unit, 'pcs', ['supplier' => $a['suppliers']['labels'], 'vat_applies' => true]);
        }

        foreach ($upTo5 as $size) {
            $f = $forecast[$size];
            if ($size === '5L' && $f['consumption_daily'] <= 0 && ($stock['BAILING-5L'] ?? 0) <= 0) {
                continue;
            }
            $bpb = max(1, (int) $a['bottles_per_bale'][$size]);
            $spb = max(1, (int) $a['sheets_per_bag']);
            $make('bailing', "Bailing paper {$size}", "BAILING-$size", $size, $f['consumption_daily'] / $bpb, $f['sigma'] / $bpb,
                (int) $leadBd['bailing'], 0, (float) $spb, (float) ($a['bailing_rate_sheet'][$size] ?? 0), 'sheets', ['bag_sheets' => $spb]);
        }

        $sealDaily = array_sum(array_map(fn ($s) => $forecast[$s]['consumption_daily'], $upTo5));
        $sealSigma = sqrt(array_sum(array_map(fn ($s) => $forecast[$s]['sigma'] ** 2, $upTo5)));
        $make('seals', 'Security seals (≤5L)', 'SEAL-BOTTLE', 'shared', $sealDaily, $sealSigma,
            (int) $leadBd['seals'], (float) $sup['moq']['seal'], 10000, (float) $a['seal']['security'], 'pcs');

        // ── Bottle trip + cash trigger ───────────────────────────────
        $trip = $this->tripPlan($a, $items, $forecast, $vf, $cover, $safety);
        $cash = $this->cashPosition($a, $trip, $today, $vf);

        // Escalate bottle items when cash can already fund the trip and any bottle is below the soon-line.
        foreach ($items as &$it) {
            if (($it['trip_item'] ?? false) && $cash['trigger'] === 'order_now' && in_array($it['status'], ['soon', 'order_now', 'critical'], true)) {
                $it['advice'] .= ' Cash already covers the next bottle trip — place the Intripet order now.';
            }
        }
        unset($it);

        $counts = ['critical' => 0, 'order_now' => 0, 'soon' => 0, 'ok' => 0, 'idle' => 0];
        foreach ($items as $it) {
            $counts[$it['status']] = ($counts[$it['status']] ?? 0) + 1;
        }
        $order = ['critical' => 0, 'order_now' => 1, 'soon' => 2, 'ok' => 3, 'idle' => 4];
        usort($items, fn ($x, $y) => [$order[$x['status']] ?? 9, $x['days_cover'] ?? 9999] <=> [$order[$y['status']] ?? 9, $y['days_cover'] ?? 9999]);

        $orders = SupplyOrder::query()->orderByRaw("CASE status WHEN 'placed' THEN 0 WHEN 'in_transit' THEN 1 ELSE 2 END")
            ->orderByDesc('order_date')->limit(30)->get();

        $hist = [];
        $totalFc = array_sum(array_map(fn ($s) => $forecast[$s]['forecast_daily'], OperationsModelService::SIZES));
        foreach ($dates as $i => $dt) {
            $hist[] = ['date' => $dt, 'bottles' => round($totalSeries[$i], 1), 'forecast' => round($totalFc, 1)];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'headline' => $counts,
            'items' => $items,
            'trip' => $trip,
            'cash' => $cash,
            'forecast' => $forecast,
            'history' => $hist,
            'orders' => $orders->map(fn ($o) => $o->toApi())->values(),
            'orders_open' => $orders->whereIn('status', ['placed', 'in_transit'])->count(),
            'settings' => [
                'vat_rate' => (float) $a['vat_rate'],
                'suppliers' => $a['suppliers'],
                'lead_business_days' => $leadBd,
                'moq' => $sup['moq'],
                'safety_days' => $safety,
                'target_cover_days' => $cover,
                'trips_per_month' => $trips,
            ],
            'model_notes' => [
                'Forecast = damped Holt trend (+ weekday seasonality after 3 weeks) on daily bottles sold, blended with the plan prior (trips/month × trip quantities) — weight on real data grows as '.self::WINDOW_DAYS.'-day history fills.',
                'Reorder point = daily use × (lead time + safety days) + z × σ × √lead time. Lead times are business days (Mon–Fri).',
                'Open supply orders count as stock on the way so alerts do not repeat after you order.',
                'Cash = opening cash + collected sales (cash & M-Pesa, not credit) − petty cash out − trip fuel − supply orders placed since the tracking date.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function adviseItem(string $group, string $label, string $code, string $size, float $daily, float $sigma, int $leadBd, float $moq, float $step, float $unitCost, string $unit, float $onHandRaw, float $onOrder, Carbon $today, int $safety, float $z, int $cover, array $extra): array
    {
        $leadCal = DemandForecaster::businessToCalendarDays($leadBd);
        $onHand = max(0.0, $onHandRaw);
        $position = $onHand + $onOrder;
        $safetyStock = $z * $sigma * sqrt(max(1, $leadCal));
        $rop = $daily * ($leadCal + $safety) + $safetyStock;
        $daysCover = $daily > 0 ? $onHand / $daily : null;
        $coverPos = $daily > 0 ? $position / $daily : null;
        $stockout = $daysCover !== null ? $today->copy()->addDays((int) floor($daysCover)) : null;
        $orderBy = $stockout ? $stockout->copy()->subDays($leadCal + $safety) : null;

        if ($daily <= 0) {
            $status = 'idle';
        } elseif ($daysCover < $leadCal) {
            $status = 'critical';
        } elseif ($position <= $rop) {
            $status = $onOrder > 0 && $daysCover >= $leadCal ? 'ok' : 'order_now';
        } elseif ($position <= $rop + $daily * 7) {
            $status = 'soon';
        } else {
            $status = 'ok';
        }

        $target = $daily * $cover + $safetyStock;
        $need = max(0.0, $target - $position);
        $suggest = 0.0;
        if (in_array($status, ['critical', 'order_now', 'soon'], true) && $need > 0) {
            $suggest = $step > 0 ? ceil($need / $step) * $step : ceil($need);
            if ($moq > 0) {
                $suggest = max($suggest, $moq);
            }
        }
        $arrival = DemandForecaster::addBusinessDays($today, $leadBd);
        $cost = $suggest * $unitCost;

        $nf = fn ($n) => number_format($n);
        $advice = match ($status) {
            'critical' => $onOrder > 0
                ? "Runs out in ".number_format((float) $daysCover, 1)." days — likely before the ".$nf($onOrder)." {$unit} already on order arrive. Chase the supplier / plan a stop-gap."
                : "Will run out in ".number_format((float) $daysCover, 1)." days — before an order placed today can arrive (".$leadBd." business days). Order ".$nf($suggest)." {$unit} NOW"
                    .($moq > 0 ? " (MOQ ".$nf($moq).")" : '').' and plan a stop-gap.',
            'order_now' => "At/below reorder point. Order ".$nf($suggest)." {$unit}".($moq > 0 ? " (MOQ ".$nf($moq).")" : '')." today; arrives ~".$arrival->format('D j M').".",
            'soon' => "Reorder point within ~7 days. Plan ".$nf($suggest)." {$unit}".($moq > 0 ? " (MOQ ".$nf($moq).")" : '').' by '.($orderBy ? $orderBy->format('j M') : 'soon').'.',
            'idle' => 'No demand forecast yet.',
            default => $onOrder > 0 ? 'Covered — '.$nf($onOrder)." {$unit} already on order." : 'Healthy cover.',
        };

        return array_merge([
            'group' => $group,
            'label' => $label,
            'code' => $code,
            'size' => $size,
            'unit' => $unit,
            'on_hand' => round($onHandRaw, 2),
            'on_order' => round($onOrder, 2),
            'daily_use' => round($daily, 3),
            'days_cover' => $daysCover === null ? null : round($daysCover, 1),
            'days_cover_incl_orders' => $coverPos === null ? null : round($coverPos, 1),
            'stockout_date' => $stockout?->toDateString(),
            'order_by_date' => $orderBy?->toDateString(),
            'lead_business_days' => $leadBd,
            'lead_calendar_days' => $leadCal,
            'safety_stock' => round($safetyStock, 1),
            'reorder_point' => round($rop, 1),
            'moq' => $moq,
            'suggest_qty' => round($suggest, 0),
            'unit_cost_incl_vat' => round($unitCost, 4),
            'est_cost' => round($cost, 2),
            'arrival_if_ordered_today' => $arrival->format('Y-m-d'),
            'status' => $status,
            'advice' => $advice,
        ], $extra);
    }

    /**
     * Bottle trip cost: standard (Excel) quantities and a forecast-sized mix.
     *
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,array<string,mixed>>  $forecast
     * @return array<string,mixed>
     */
    private function tripPlan(array $a, array $items, array $forecast, float $vf, int $cover, int $safety): array
    {
        $transport = (float) $a['transport_per_trip'];
        $line = function (string $size, float $qty) use ($a, $vf) {
            $unit = ((float) $a['bottle_ex_vat'][$size] + (float) $a['cap_ex_vat']) * $vf;

            return [
                'size' => $size,
                'qty' => $qty,
                'bottle_ex_vat' => (float) $a['bottle_ex_vat'][$size],
                'cap_ex_vat' => (float) $a['cap_ex_vat'],
                'unit_cost_incl_vat' => round($unit, 4),
                'cost' => round($qty * $unit, 2),
            ];
        };
        $standard = [];
        $suggested = [];
        $byCode = [];
        foreach ($items as $it) {
            $byCode[$it['code']] = $it;
        }
        foreach (OperationsModelService::BOTTLED as $size) {
            $standard[] = $line($size, (float) ($a['trip_bottles'][$size] ?? 0));
            $it = $byCode["BOTTLE-$size"] ?? null;
            $need = 0.0;
            if ($it) {
                $target = $it['daily_use'] * $cover + $it['safety_stock'];
                $need = max(0.0, $target - ($it['on_hand'] + $it['on_order']));
                $need = ceil($need / 100) * 100;
            }
            $suggested[] = $line($size, $need);
        }
        $sum = fn ($rows) => array_sum(array_column($rows, 'cost'));
        $standardCost = $sum($standard) + $transport;
        $suggestedBottles = $sum($suggested);
        $override = $a['supply']['trip_cash_target_override'] ?? null;

        return [
            'transport' => $transport,
            'standard' => ['lines' => $standard, 'bottles_cost' => round($sum($standard), 2), 'total' => round($standardCost, 2)],
            'suggested' => [
                'lines' => $suggested,
                'bottles_cost' => round($suggestedBottles, 2),
                'total' => round($suggestedBottles + $transport, 2),
                'total_qty' => array_sum(array_column($suggested, 'qty')),
            ],
            'cash_target' => round($override !== null && (float) $override > 0 ? (float) $override : $standardCost, 2),
            'cash_target_source' => $override !== null && (float) $override > 0 ? 'manual override' : 'standard trip (bottles + caps incl. 16% VAT + transport)',
            'lead_business_days' => (int) $a['supply']['lead_business_days']['bottles'],
        ];
    }

    /** @return array<string,mixed> */
    private function cashPosition(array $a, array $trip, Carbon $today, float $vf): array
    {
        $sup = $a['supply'];
        $start = ! empty($sup['cash_tracking_start'])
            ? Carbon::parse($sup['cash_tracking_start'])->startOfDay()
            : $today->copy()->startOfMonth();
        $s = $start->toDateString();
        $t = $today->toDateString();

        $collected = $this->revenue->combinedRevenueBetween($s, $t);
        $petty = Schema::hasTable('petty_cash_entries')
            ? (float) PettyCashEntry::whereNull('deleted_at')->whereBetween('entry_date', [$s, $t])->sum('amount_out') : 0.0;
        $fuel = (float) DriverTrip::whereNull('deleted_at')->whereBetween('trip_date', [$s, $t])->sum('fuel_cost');
        $supply = (float) SupplyOrder::query()->where('status', '!=', 'cancelled')->whereDate('order_date', '>=', $s)->sum('total_cost');
        $opening = (float) ($sup['opening_cash'] ?? 0);
        $cash = $opening + $collected - $petty - $fuel - $supply;

        $pay = $a['payroll'];
        $basis = ($a['payroll_basis'] ?? 'high') === 'low' ? 'low' : 'high';
        $tripsPm = (int) max(1, min(5, round((float) $sup['trips_per_month'])));
        $monthlyFixed = (float) $pay['accountant_manager'] + (float) $pay["driver_$basis"] + (float) $pay["sales_$basis"]
            + (float) $pay['finalis'] + (float) $a['jim_stipend']["t{$tripsPm}"] + (float) array_sum($a['overheads']);
        $dim = $today->daysInMonth;
        $remainingFrac = ($dim - $today->day + 1) / $dim;
        $reserve = $monthlyFixed * $remainingFrac;

        $target = (float) $trip['cash_target'];
        $pct = $target > 0 ? $cash / $target : 0;
        $trigger = $cash >= $target ? 'order_now' : ($pct >= 0.8 ? 'almost' : 'saving');

        $recent = $this->revenue->combinedRevenueBetween($today->copy()->subDays(13)->toDateString(), $t) / 14;
        $remaining = max(0.0, $target - $cash);
        $eta = ($remaining > 0 && $recent > 0) ? (int) ceil($remaining / $recent) : null;

        $headline = match ($trigger) {
            'order_now' => 'Cash KES '.number_format($cash).' covers the next bottle trip (KES '.number_format($target).'). Place the Intripet order now — '
                .$trip['lead_business_days'].' business days to arrive.',
            'almost' => 'KES '.number_format($remaining).' short of the next bottle trip ('.round($pct * 100).'% funded)'.($eta ? " — about {$eta} day(s) at the recent sales pace." : '.'),
            default => 'Saving for the next bottle trip: KES '.number_format($cash).' of KES '.number_format($target).' ('.round(max(0, $pct) * 100).'%)'.($eta ? " — about {$eta} day(s) at the recent sales pace." : '.'),
        };

        return [
            'tracking_start' => $s,
            'opening_cash' => $opening,
            'collected_sales' => round($collected, 2),
            'petty_cash_out' => round($petty, 2),
            'trip_fuel' => round($fuel, 2),
            'supply_orders_placed' => round($supply, 2),
            'cash_available' => round($cash, 2),
            'monthly_fixed_costs' => round($monthlyFixed, 2),
            'fixed_reserve_remaining' => round($reserve, 2),
            'after_fixed_costs' => round($cash - $reserve, 2),
            'covers_fixed_costs' => $cash - $reserve >= $target ? true : false,
            'trip_target' => round($target, 2),
            'funded_pct' => round($pct, 4),
            'remaining' => round($remaining, 2),
            'recent_daily_sales' => round($recent, 2),
            'eta_days' => $eta,
            'trigger' => $trigger,
            'headline' => $headline,
            'warning' => ($trigger === 'order_now' && $cash - $target < $reserve)
                ? 'Ordering leaves KES '.number_format($cash - $target).' against ~KES '.number_format($reserve).' of payroll & overheads still due this month — keep collecting before payday.'
                : null,
        ];
    }

    /** @return array<string,array<string,float>> date => size => bottles */
    private function bottlesByDaySize(string $kind, string $from, string $to, array $a): array
    {
        $rows = collect();
        if ($kind === 'sales') {
            $orders = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('skus', 'skus.id', '=', 'order_items.sku_id')
                ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
                ->whereNotNull('orders.payment_method')
                ->whereBetween('orders.order_date', [$from, $to])
                ->selectRaw('orders.order_date as d, skus.size_liters, skus.unit, skus.brand, skus.code, SUM(order_items.qty - order_items.qty_returned) as q')
                ->groupBy('d', 'skus.size_liters', 'skus.unit', 'skus.brand', 'skus.code')->get();
            $trips = DB::table('driver_trip_items')
                ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
                ->join('skus', 'skus.id', '=', 'driver_trip_items.sku_id')
                ->whereNull('driver_trips.deleted_at')
                ->whereBetween('driver_trips.trip_date', [$from, $to])
                ->selectRaw('driver_trips.trip_date as d, skus.size_liters, skus.unit, skus.brand, skus.code, SUM(driver_trip_items.qty_sold) as q')
                ->groupBy('d', 'skus.size_liters', 'skus.unit', 'skus.brand', 'skus.code')->get();
            $rows = $orders->concat($trips);
        } else {
            $rows = DB::table('packaging_runs')
                ->join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
                ->whereNull('packaging_runs.deleted_at')->whereNotNull('packaging_runs.run_end')
                ->whereBetween(DB::raw('DATE(packaging_runs.run_end)'), [$from, $to])
                ->selectRaw('DATE(packaging_runs.run_end) as d, skus.size_liters, skus.unit, skus.brand, skus.code, SUM(packaging_runs.good_qty) as q')
                ->groupBy('d', 'skus.size_liters', 'skus.unit', 'skus.brand', 'skus.code')->get();
        }

        $out = [];
        foreach ($rows as $r) {
            if (strtoupper((string) $r->brand) === 'REFILL' || str_starts_with((string) $r->code, 'REFILL')) {
                continue; // refills re-use existing containers — no new bottle/label consumption
            }
            $size = OperationsModelService::sizeLabel((float) $r->size_liters);
            if ($size === null) {
                continue;
            }
            $bpb = strtoupper((string) $r->unit) === 'BOTTLE' ? (int) ($a['bottles_per_bale'][$size] ?? 1) : 1;
            $d = (string) $r->d;
            $out[$d][$size] = ($out[$d][$size] ?? 0) + ((float) $r->q) * $bpb;
        }

        return $out;
    }

    /** @return array{avg: float, days: int} */
    private function avgDaily(array $byDay, string $size, array $dates): array
    {
        $first = null;
        foreach ($dates as $i => $dt) {
            if (($byDay[$dt][$size] ?? 0) > 0) {
                $first = $i;
                break;
            }
        }
        if ($first === null) {
            return ['avg' => 0.0, 'days' => 0];
        }
        $days = count($dates) - $first;
        $sum = 0.0;
        for ($i = $first; $i < count($dates); $i++) {
            $sum += (float) ($byDay[$dates[$i]][$size] ?? 0);
        }

        return ['avg' => round($sum / max(1, $days), 3), 'days' => $days];
    }

    /** @param list<string> $codes @return array<string,float> */
    private function materialStock(array $codes): array
    {
        $mats = DB::table('materials')->whereIn('code', $codes)->whereNull('deleted_at')->pluck('id', 'code');
        if ($mats->isEmpty()) {
            return [];
        }
        $sums = DB::table('stock_items')->where('item_type', 'material')->whereNull('deleted_at')
            ->whereIn('material_id', $mats->values()->all())
            ->selectRaw('material_id, SUM(qty) as q')->groupBy('material_id')->pluck('q', 'material_id');
        $out = [];
        foreach ($mats as $code => $id) {
            $out[$code] = (float) ($sums[$id] ?? 0);
        }

        return $out;
    }

    /** @return array<string,float> item code => qty on the way */
    private function openOrderQty(): array
    {
        $out = [];
        foreach (SupplyOrder::query()->whereIn('status', ['placed', 'in_transit'])->get() as $o) {
            foreach ((array) $o->lines as $l) {
                $code = (string) ($l['item_code'] ?? '');
                if ($code !== '') {
                    $out[$code] = ($out[$code] ?? 0) + (float) ($l['qty'] ?? 0);
                }
            }
        }

        return $out;
    }
}
