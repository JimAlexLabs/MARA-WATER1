<?php

namespace App\Services;

/**
 * Live P&L model from MARA WATER Hypothesis Model.xlsx assumptions,
 * with current suggested selling prices.
 */
class HypothesisModelService
{
    /**
     * @param  array<string,mixed>|null  $overrides
     * @return array<string,mixed>
     */
    public function snapshot(?array $overrides = null): array
    {
        $h = config('mara_operations.hypothesis', []);
        if (is_array($overrides) && $overrides !== []) {
            $h = array_replace_recursive($h, $overrides);
        }

        $vat = (float) ($h['materials_ex_vat']['vat'] ?? 0.16);
        $bpb = config('mara_operations.bottles_per_bale_by_size', []);
        $tripBottles = $h['trip_bottles'] ?? [];
        $transport = (float) ($h['transport_per_trip_kes'] ?? 45000);
        $totalTripBottles = array_sum(array_map('floatval', $tripBottles)) ?: 1;
        $transportPerBottle = $transport / $totalTripBottles;

        $costRows = [];
        $tripLines = [];
        $revenue = 0.0;
        $costTotal = 0.0;

        foreach ([0.5, 1.0, 1.5, 5.0, 10.0, 20.0] as $size) {
            $materials = $this->materialsCostPerBottle($h, $size, $vat, $bpb);
            $wage = (float) ($h['wages_per_bottle'][$size] ?? $h['wages_per_bottle'][(string) $size] ?? 0);
            $usesTransport = isset($tripBottles[$size]) || isset($tripBottles[(string) $size]);
            $transportBtl = $usesTransport ? $transportPerBottle : 0.0;
            $cop = $materials + $wage + $transportBtl;
            $priceBale = (float) ($h['selling_prices_bale']['premium'][$size]
                ?? $h['selling_prices_bale']['premium'][(string) $size]
                ?? 0);
            $bottlesPerBale = (int) ($bpb[$size] ?? $bpb[(string) $size] ?? 1);
            $priceBottle = $bottlesPerBale > 0 ? $priceBale / $bottlesPerBale : $priceBale;

            $costRows[] = [
                'size' => $this->sizeLabel($size),
                'size_liters' => $size,
                'materials_per_bottle' => round($materials, 4),
                'wage_per_bottle' => round($wage, 4),
                'transport_per_bottle' => round($transportBtl, 4),
                'cost_of_production_per_bottle' => round($cop, 4),
                'price_per_bale' => $priceBale,
                'price_per_bottle' => round($priceBottle, 4),
                'bottles_per_bale' => $bottlesPerBale,
                'gross_margin_per_bottle' => round($priceBottle - $materials, 4),
            ];

            $qty = (float) ($tripBottles[$size] ?? $tripBottles[(string) $size] ?? 0);
            if ($qty > 0) {
                $lineRev = $qty * $priceBottle;
                $lineCost = $qty * $cop;
                $revenue += $lineRev;
                $costTotal += $lineCost;
                $tripLines[] = [
                    'sku' => 'Premium '.$this->sizeLabel($size),
                    'qty_bottles' => $qty,
                    'cost_per_bottle' => round($cop, 4),
                    'price_per_bottle' => round($priceBottle, 4),
                    'revenue' => round($lineRev, 2),
                    'cost_total' => round($lineCost, 2),
                ];
            }
        }

        $gross = $revenue - $costTotal;
        $overheads = array_sum(array_map('floatval', $h['overheads_monthly'] ?? []));
        $payroll = $h['payroll_monthly'] ?? [];
        $payrollLow = (float) ($payroll['accountant_manager'] ?? 25000)
            + (float) ($payroll['driver_low'] ?? 20000)
            + (float) ($payroll['sales_low'] ?? 20000)
            + (float) ($payroll['finalis'] ?? 15000);
        $payrollHigh = (float) ($payroll['accountant_manager'] ?? 25000)
            + (float) ($payroll['driver_high'] ?? 25000)
            + (float) ($payroll['sales_high'] ?? 25000)
            + (float) ($payroll['finalis'] ?? 15000);

        $monthly = [];
        for ($trips = 1; $trips <= 5; $trips++) {
            $jim = (float) ($payroll['jim_stipend_by_trips'][$trips] ?? $payroll['jim_stipend_by_trips'][(string) $trips] ?? 75000);
            $rev = $revenue * $trips;
            $cogs = $costTotal * $trips;
            $gm = $rev - $cogs;
            $monthly[] = [
                'trips' => $trips,
                'revenue' => round($rev, 2),
                'cost_of_production' => round($cogs, 2),
                'gross_margin' => round($gm, 2),
                'jim_stipend' => $jim,
                'payroll_low' => round($payrollLow + $jim, 2),
                'payroll_high' => round($payrollHigh + $jim, 2),
                'overheads' => round($overheads, 2),
                'net_low' => round($gm - $payrollLow - $jim - $overheads, 2),
                'net_high' => round($gm - $payrollHigh - $jim - $overheads, 2),
            ];
        }

        $cap = $h['capacity'] ?? [];
        $bph = (int) ($cap['bottles_per_hour'] ?? 2400);
        $hpd = (float) ($cap['hours_per_day'] ?? 4);
        $bottlesPerDay = (int) round($bph * $hpd);
        $daysToFillTrip = $bottlesPerDay > 0 ? round($totalTripBottles / $bottlesPerDay, 2) : null;

        $tripsAssumed = (int) ($h['trips_assumed'] ?? 1);
        if ($tripsAssumed < 1) {
            $tripsAssumed = 1;
        }
        if ($tripsAssumed > 5) {
            $tripsAssumed = 5;
        }
        $phaseKey = $tripsAssumed >= 2 ? 'phase1_multi_trip' : 'phase1_one_trip';
        $splits = $h['profit_split'] ?? [];
        $activeSplit = $splits[$phaseKey] ?? ($splits['phase1_one_trip'] ?? []);
        $bestNet = (float) ($monthly[$tripsAssumed - 1]['net_high'] ?? 0);
        $profitAllocation = [];
        foreach ($activeSplit as $name => $pct) {
            $profitAllocation[] = [
                'name' => $name,
                'pct' => round((float) $pct * 100, 1),
                'amount' => round($bestNet * (float) $pct, 2),
            ];
        }

        return [
            'assumptions' => $h,
            'cost_build' => $costRows,
            'trip' => [
                'lines' => $tripLines,
                'bottles_total' => $totalTripBottles,
                'revenue' => round($revenue, 2),
                'cost_total' => round($costTotal, 2),
                'gross_margin' => round($gross, 2),
                'transport_per_trip' => $transport,
                'supplier' => $h['bottle_supplier'] ?? 'Intripet Limited',
            ],
            'capacity' => [
                'bottles_per_hour' => $bph,
                'hours_per_day' => $hpd,
                'bottles_per_day' => $bottlesPerDay,
                'days_to_fill_trip' => $daysToFillTrip,
            ],
            'profit_split' => [
                'phase' => $phaseKey,
                'rows' => $profitAllocation,
                'all_phases' => $splits,
            ],
            'monthly' => $monthly,
            'prices' => $h['selling_prices_bale'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $h
     * @param  array<float|string,int>  $bpb
     */
    private function materialsCostPerBottle(array $h, float $size, float $vat, array $bpb): float
    {
        $m = $h['materials_ex_vat'] ?? [];
        $factor = 1 + $vat;
        if (in_array($size, [5.0, 10.0, 20.0], true)) {
            $bottleCap = (float) ($m['bottle_cap_final'][$size] ?? $m['bottle_cap_final'][(string) $size] ?? 0);
        } else {
            $bottle = (float) ($m['bottle'][$size] ?? $m['bottle'][(string) $size] ?? 0);
            $cap = (float) ($m['cap'] ?? 0);
            $bottleCap = ($bottle + $cap) * $factor;
        }
        $label = (float) ($m['label'][$size] ?? $m['label'][(string) $size] ?? 0) * $factor;
        $sealRaw = $m['seal'] ?? 0.3;
        $seal = is_array($sealRaw)
            ? (float) ($sealRaw[$size] ?? $sealRaw[(string) $size] ?? 0.3)
            : (float) $sealRaw;
        $bottlesPerBale = (int) ($bpb[$size] ?? $bpb[(string) $size] ?? 1);
        $sheetRate = (float) ($m['bailing_sheet'][$size] ?? $m['bailing_sheet'][(string) $size] ?? 0);
        $bailing = ($bottlesPerBale > 0 && $sheetRate > 0) ? ($sheetRate / $bottlesPerBale) : 0.0;

        return $bottleCap + $label + $bailing + $seal;
    }

    private function sizeLabel(float $size): string
    {
        if (abs($size - (int) $size) < 0.011) {
            return ((int) $size).'L';
        }

        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.').'L';
    }
}
