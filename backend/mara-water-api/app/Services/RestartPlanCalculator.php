<?php

namespace App\Services;

/**
 * Server-side what-if engine for the Director Premium Restart Plan.
 * Mirrors docs/operations/MARA_WATER_Premium_Restart_Plan_2.xlsx logic.
 */
class RestartPlanCalculator
{
    public static function defaults(): array
    {
        return [
            'payroll' => [
                'driver' => ['label' => 'Driver', 'count' => 1, 'pay_each' => 20000],
                'sales' => ['label' => 'Sales person', 'count' => 1, 'pay_each' => 20000],
                'manager' => ['label' => 'Accountant / manager', 'count' => 1, 'pay_each' => 25000],
                'production' => ['label' => 'Production ladies', 'count' => 6, 'pay_each' => 10000],
            ],
            'overheads' => [
                'fuel' => 35000,
                'electricity' => 10000,
                'plumbing_repairs' => 6000,
                'airtime' => 4000,
                'miscellaneous' => 2000,
                'parking_county' => 3000,
                'printing_labels' => 6000,
            ],
            'transport_per_trip' => 45000,
            'lorry_materials_cost' => 160429.59,
            'lorry_projected_revenue' => 357545.0,
            'md_stipend' => 80000,
            'alt_stipend' => 65000,
            'starting_capital' => [
                'jimal' => 200000,
                'diana' => 100000,
            ],
            'company_retain_pct' => 0.25,
            'jimal_of_distributable_pct' => 0.70,
            'restocks_per_month' => 3,
            'profit_targets' => [300000, 500000],
            'operating_days_per_month' => 26,
            'sept_daily_pace' => 33853.75,
            'sept_weekly_pace' => 203122.5,
            'production' => [
                'ladies' => 6,
                'bottles_per_worker_day' => 173.0,
                'lorry_bottles' => 16045,
                'kept_mix_monthly_bottles' => 30586,
            ],
            'timeline' => [
                ['when' => 'Now – Oct 3', 'action' => 'Place first full-lorry materials order (~160k + 45k transport).'],
                ['when' => 'Now – Oct 7', 'action' => 'Complete machine repairs flagged in warehouse audit.'],
                ['when' => 'Oct 8 – 9', 'action' => 'Receive, count and store lorry stock; brief production + driver.'],
                ['when' => 'Oct 10', 'action' => 'Production and sales begin (Premium-only mix).'],
                ['when' => 'Oct 10 – ~20', 'action' => 'Sell through first lorry (~10–11 selling days at Sept pace).'],
                ['when' => '~Oct 20 – 22', 'action' => 'Place second lorry once Cycle-1 cash is in.'],
                ['when' => 'Ongoing', 'action' => 'Recheck 6-lady capacity monthly; never partial-order — full lorry only.'],
            ],
            'sku_mix_note' => 'Premium-only (+ refills). Platinum / Grace dropped.',
        ];
    }

    public function merge(array $overrides = []): array
    {
        return array_replace_recursive(self::defaults(), $overrides);
    }

    public function compute(array $assumptions): array
    {
        $a = $this->merge($assumptions);

        $payrollTotal = 0.0;
        $payrollRows = [];
        foreach ($a['payroll'] as $key => $row) {
            $count = (int) ($row['count'] ?? 0);
            $pay = (float) ($row['pay_each'] ?? 0);
            $total = $count * $pay;
            $payrollTotal += $total;
            $payrollRows[] = [
                'key' => $key,
                'label' => $row['label'] ?? $key,
                'count' => $count,
                'pay_each' => $pay,
                'monthly_total' => $total,
            ];
        }

        $overheadTotal = 0.0;
        $overheadRows = [];
        foreach ($a['overheads'] as $key => $val) {
            $amt = (float) $val;
            $overheadTotal += $amt;
            $overheadRows[] = ['key' => $key, 'label' => str_replace('_', ' ', ucfirst($key)), 'monthly_cost' => $amt];
        }

        $fixedMonthly = $payrollTotal + $overheadTotal;
        $mdStipend = (float) $a['md_stipend'];
        $altStipend = (float) $a['alt_stipend'];
        $cashCostBeforeShare = $fixedMonthly + $mdStipend;

        $lorryMat = (float) $a['lorry_materials_cost'];
        $lorryRev = (float) $a['lorry_projected_revenue'];
        $transport = (float) $a['transport_per_trip'];
        $firstOutlay = $lorryMat + $transport;

        $jimalCap = (float) ($a['starting_capital']['jimal'] ?? 0);
        $dianaCap = (float) ($a['starting_capital']['diana'] ?? 0);
        $totalCap = $jimalCap + $dianaCap;
        $cashBuffer = $totalCap - $firstOutlay;

        $grossMargin = $lorryRev - $lorryMat;
        $vat = $grossMargin * 0.16 / 1.16;
        $afterVat = $grossMargin - $vat;
        $netPerLorry = $afterVat - $transport;
        $contribPerRevenue = $lorryRev > 0 ? ($netPerLorry / $lorryRev) : 0.0;

        $restocks = max(1, (int) $a['restocks_per_month']);
        $scenarioPnls = [];
        foreach ([1, 2, 3, 4] as $n) {
            $rev = $n * $lorryRev;
            $mat = $n * $lorryMat;
            $gm = $rev - $mat;
            $v = $gm * 0.16 / 1.16;
            $gp = $gm - $v;
            $tr = $n * $transport;
            $net80 = $gp - $tr - $fixedMonthly - $mdStipend;
            $net65 = $gp - $tr - $fixedMonthly - $altStipend;
            $scenarioPnls[] = [
                'restocks' => $n,
                'revenue' => round($rev, 2),
                'materials' => round($mat, 2),
                'gross_margin' => round($gm, 2),
                'vat' => round($v, 2),
                'gross_after_vat' => round($gp, 2),
                'transport' => round($tr, 2),
                'fixed_costs' => round($fixedMonthly, 2),
                'net_at_md_stipend' => round($net80, 2),
                'net_at_alt_stipend' => round($net65, 2),
            ];
        }

        $chosen = $scenarioPnls[$restocks - 1] ?? $scenarioPnls[2];
        $netProfit = $chosen['net_at_md_stipend'];
        $retainPct = (float) $a['company_retain_pct'];
        $jimalPct = (float) $a['jimal_of_distributable_pct'];
        $dianaPct = max(0, 1 - $jimalPct);
        $retained = max(0, $netProfit) * $retainPct;
        $distributable = max(0, $netProfit) * (1 - $retainPct);
        $jimalShare = $distributable * $jimalPct;
        $dianaShare = $distributable * $dianaPct;

        $targets = [];
        $days = max(1, (int) $a['operating_days_per_month']);
        $septDaily = (float) $a['sept_daily_pace'];
        foreach ((array) $a['profit_targets'] as $target) {
            $target = (float) $target;
            foreach ([['label' => 'md_stipend', 'stipend' => $mdStipend], ['label' => 'alt_stipend', 'stipend' => $altStipend]] as $opt) {
                $needRev = $contribPerRevenue > 0
                    ? ($target + $fixedMonthly + $opt['stipend']) / $contribPerRevenue
                    : 0;
                $lorries = $lorryRev > 0 ? $needRev / $lorryRev : 0;
                $daily = $needRev / $days;
                $targets[] = [
                    'profit_target' => $target,
                    'stipend_label' => $opt['label'],
                    'stipend' => $opt['stipend'],
                    'required_monthly_revenue' => round($needRev, 2),
                    'equivalent_lorries' => round($lorries, 3),
                    'min_restock_orders' => (int) ceil($lorries),
                    'required_daily_sales' => round($daily, 2),
                    'vs_sept_daily_pace' => $septDaily > 0 ? round($daily / $septDaily, 3) : null,
                ];
            }
        }

        // Weekly cash tracker (6 weeks) at Sept weekly pace
        $weeks = [];
        $running = $totalCap;
        $septWeek = (float) $a['sept_weekly_pace'];
        $lorryOutlay = -1 * $firstOutlay;
        $cumStock = 0.0;
        for ($w = 1; $w <= 6; $w++) {
            $sales = $septWeek;
            $running = $running + $lorryOutlay + $sales;
            $cumStock += $firstOutlay;
            $weeks[] = [
                'week' => $w,
                'lorry_outlay' => round($lorryOutlay, 2),
                'sales_at_sept_pace' => round($sales, 2),
                'cumulative_stock_bought' => round($cumStock, 2),
                'running_cash' => round($running, 2),
            ];
        }

        $ladies = (int) ($a['production']['ladies'] ?? 6);
        $bpwd = (float) ($a['production']['bottles_per_worker_day'] ?? 173);
        $workerDays = $ladies * $days;
        $capacityBottles = $workerDays * $bpwd;
        $lorryBottles = (float) ($a['production']['lorry_bottles'] ?? 16045);
        $keptDemand = (float) ($a['production']['kept_mix_monthly_bottles'] ?? 30586);
        $daysToProcessLorry = $bpwd > 0 && $ladies > 0 ? $lorryBottles / ($ladies * $bpwd) : null;
        $daysToSellLorry = $septDaily > 0 && $lorryMat > 0
            ? ($lorryMat / (($lorryMat / max(1, $lorryRev)) * ($lorryRev / max(1, ($lorryRev / $septDaily))))) // unused fallback
            : null;
        // Sellout days at Sept kept-mix daily materials pace ≈ materials / (sept daily * materials/revenue)
        $matPerRev = $lorryRev > 0 ? $lorryMat / $lorryRev : 0;
        $dailyMatPace = $septDaily * $matPerRev;
        $selloutDays = $dailyMatPace > 0 ? $lorryMat / $dailyMatPace : null;

        $breakevenRestocks = null;
        foreach ($scenarioPnls as $row) {
            if ($row['net_at_md_stipend'] >= 0) {
                $breakevenRestocks = $row['restocks'];
                break;
            }
        }

        return [
            'assumptions' => $a,
            'totals' => [
                'payroll' => round($payrollTotal, 2),
                'overheads' => round($overheadTotal, 2),
                'fixed_monthly' => round($fixedMonthly, 2),
                'md_stipend' => round($mdStipend, 2),
                'cash_cost_before_profit_share' => round($cashCostBeforeShare, 2),
                'first_outlay' => round($firstOutlay, 2),
                'starting_capital' => round($totalCap, 2),
                'cash_buffer_at_start' => round($cashBuffer, 2),
                'contrib_per_revenue' => round($contribPerRevenue, 6),
                'net_per_full_lorry_after_vat_transport' => round($netPerLorry, 2),
            ],
            'payroll_rows' => $payrollRows,
            'overhead_rows' => $overheadRows,
            'restock_scenarios' => $scenarioPnls,
            'chosen_restocks' => $restocks,
            'chosen_pnl' => $chosen,
            'profit_share' => [
                'net_company_profit' => round($netProfit, 2),
                'company_retain_pct' => $retainPct,
                'company_retained' => round($retained, 2),
                'distributable' => round($distributable, 2),
                'jimal_pct' => $jimalPct,
                'diana_pct' => $dianaPct,
                'jimal_share' => round($jimalShare, 2),
                'diana_share' => round($dianaShare, 2),
                'jimal_total_with_stipend' => round($jimalShare + $mdStipend, 2),
            ],
            'targets' => $targets,
            'weekly_cash' => $weeks,
            'capacity' => [
                'worker_days_per_month' => $workerDays,
                'projected_monthly_bottles' => round($capacityBottles, 1),
                'capacity_vs_kept_demand_pct' => $keptDemand > 0 ? round($capacityBottles / $keptDemand, 3) : null,
                'days_to_process_lorry' => $daysToProcessLorry !== null ? round($daysToProcessLorry, 2) : null,
                'days_to_sell_lorry_at_sept_pace' => $selloutDays !== null ? round($selloutDays, 2) : null,
            ],
            'kpis' => [
                'net_profit_at_chosen_restocks' => round($netProfit, 2),
                'breakeven_restocks_at_md_stipend' => $breakevenRestocks,
                'cash_buffer_at_start' => round($cashBuffer, 2),
                'pace_gap_weekly_full_lorry' => $septWeek > 0 ? round($lorryRev / $septWeek, 3) : null,
            ],
            'timeline' => $a['timeline'],
        ];
    }
}
