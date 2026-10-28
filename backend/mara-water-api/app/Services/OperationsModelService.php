<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Setting;
use App\Models\Sku;

/**
 * MARA WATER Hypothesis Model (Oct 2026 Excel) as a live, admin-only model.
 *
 * Sheets mirrored 1:1: Assumptions, Cost Build, SKU Margins, Trip P&L,
 * Profit Split, Funding & Startup. Differences vs the Excel, by request:
 *  - Selling prices come from the application's default retail price list
 *    (never from the Excel).
 *  - Payroll defaults updated (manager 26k; driver/sales 18k base -> 25k).
 *  - Conveyor / production-rate block removed.
 *
 * IMPORTANT: sizes are keyed by label ('0.5L', '1L', ...). Never key arrays
 * by float sizes — PHP casts 0.5/1.0/1.5 to ints and 1.0 / 1.5 collide.
 */
class OperationsModelService
{
    public const SETTING_KEY = 'operations_model';

    public const SIZES = ['0.5L', '1L', '1.5L', '5L', '10L', '20L'];
    public const BOTTLED = ['0.5L', '1L', '1.5L'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'suppliers' => [
                'bottles' => 'Intripet Limited',
                'large_bottles' => 'Blowplast Limited (quote; Intripet also possible)',
                'labels' => 'NEOPACK',
            ],
            'vat_rate' => 0.16,
            'bottle_ex_vat' => ['0.5L' => 5.2, '1L' => 8.7, '1.5L' => 12.2],
            'cap_ex_vat' => 0.8,
            'bottle_cap_final' => ['5L' => 36.48, '10L' => 70.9, '20L' => 145.42],
            'label_ex_vat' => ['0.5L' => 0.6, '1L' => 1.15, '1.5L' => 1.15],
            'label_large_final' => 3.0,
            'bailing_rate_sheet' => ['0.5L' => 23.0, '1L' => 24.0, '1.5L' => 27.0, '5L' => 32.0],
            'bottles_per_bale' => ['0.5L' => 24, '1L' => 12, '1.5L' => 12, '5L' => 4, '10L' => 1, '20L' => 1],
            'sheets_per_bag' => 500,
            'seal' => ['security' => 0.3, 'topseal' => 1.2, 'security_large' => 1.2],
            'wage_per_bottle' => ['0.5L' => 0.5, '1L' => 1.0, '1.5L' => 1.5, '5L' => 5.0, '10L' => 10.0, '20L' => 20.0],
            'trip_bottles' => ['0.5L' => 23800, '1L' => 2640, '1.5L' => 5712],
            'transport_per_trip' => 45000,
            'refill_revenue_pct' => 0.0317,

            'payroll' => [
                'accountant_manager' => 26000,
                'driver_low' => 18000,
                'driver_high' => 25000,
                'sales_low' => 18000,
                'sales_high' => 25000,
                'finalis' => 15000,
            ],
            // 'high' = base + bonuses (the ~25k average to plan with for the next 2 months).
            'payroll_basis' => 'high',
            'jim_stipend' => ['t1' => 60000, 't2' => 65000, 't3' => 70000, 't4' => 75000, 't5' => 75000],
            'overheads' => ['fuel' => 40000, 'electricity' => 15000, 'airtime' => 3000, 'parking' => 3000, 'emergency' => 5000],

            'profit_split' => [
                'phase1_one' => ['Jim' => 0.30, 'Derrick' => 0.20, 'Diana' => 0.20, 'Onroy' => 0.20, 'Company' => 0.10],
                'phase1_multi' => ['Jim' => 0.30, 'Derrick' => 0.30, 'Diana' => 0.15, 'Onroy' => 0.15, 'Company' => 0.10],
                'phase2' => ['Jim' => 0.30, 'Derrick' => 0.15, 'Diana' => 0.20, 'Onroy' => 0.20, 'Company' => 0.15],
            ],
            'capital' => ['diana_onroy' => 40000, 'derrick_given' => 365000, 'derrick_pending' => 35000],
            'loan' => ['principal' => 25000, 'rate' => 0.2, 'due' => '6 November 2026'],
            'spend_items' => [
                ['label' => 'Labels — NEOPACK order, SO/13235, 22-Oct-26', 'full' => 74820, 'paid' => 74820, 'note' => 'paid in full'],
                ['label' => 'Bottles + caps — Intripet order, SO-000930, 10-Oct-26', 'full' => 242136, 'paid' => 242136, 'note' => 'paid in full'],
                ['label' => 'Transport, bottles Nairobi -> Rongo', 'full' => 45000, 'paid' => 45000, 'note' => ''],
                ['label' => 'Shadey Plumbing Co. — Inv SPC-2026-1042, 8-Oct-26', 'full' => 33500, 'paid' => 27500, 'note' => '6,000 retainage on completion'],
                ['label' => 'Shadey Plumbing & Technical Services Ltd — Ref 27/26, 15-Oct-26', 'full' => 39270, 'paid' => 36270, 'note' => '3,000 retainage on completion'],
                ['label' => 'Extra wiring cables', 'full' => 5000, 'paid' => 5000, 'note' => ''],
                ['label' => 'Fuel (restart logistics, one-off)', 'full' => 5000, 'paid' => 5000, 'note' => 'separate from monthly fuel overhead'],
            ],
            // null = use live inventory stock; a number = manual confirmed warehouse count (sheets).
            'bailing_on_hand' => ['0.5L' => null, '1L' => null, '1.5L' => null, '5L' => null],

            // Supply planning (Operations reorder advisor).
            'supply' => [
                'moq' => [
                    'label_0.5L' => 50000, 'label_1L' => 30000, 'label_1.5L' => 30000,
                    'seal' => 100000, 'bailing_bag' => 1,
                ],
                'lead_business_days' => ['bottles' => 7, 'labels' => 7, 'bailing' => 3, 'seals' => 7],
                'safety_days' => 3,
                'target_cover_days' => 45,
                'service_level_z' => 1.65,
                'trips_per_month' => 2,
                'operating_days_per_month' => 26,
                'trip_cash_target_override' => null,
                'opening_cash' => 0,
                'cash_tracking_start' => null,
            ],
        ];
    }

    /** Stored overrides merged over defaults. */
    public function effective(): array
    {
        $stored = [];
        $row = Setting::query()->find(self::SETTING_KEY);
        if ($row && is_string($row->value)) {
            $decoded = json_decode($row->value, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        return self::merge(self::defaults(), $stored);
    }

    /**
     * Keep only keys that exist in defaults, coerce types, and enforce sane ranges.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $defaults
     * @return array<string,mixed>
     */
    public static function sanitize(array $input, ?array $defaults = null): array
    {
        $defaults ??= self::defaults();
        $out = [];
        foreach ($input as $k => $v) {
            if (! array_key_exists($k, $defaults)) {
                continue;
            }
            $d = $defaults[$k];
            if ($k === 'spend_items') {
                if (is_array($v)) {
                    $out[$k] = [];
                    foreach (array_slice($v, 0, 40) as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $out[$k][] = [
                            'label' => mb_substr((string) ($row['label'] ?? ''), 0, 200),
                            'full' => max(0.0, (float) ($row['full'] ?? 0)),
                            'paid' => max(0.0, (float) ($row['paid'] ?? 0)),
                            'note' => mb_substr((string) ($row['note'] ?? ''), 0, 200),
                        ];
                    }
                }
                continue;
            }
            if ($k === 'payroll_basis') {
                $out[$k] = $v === 'low' ? 'low' : 'high';
                continue;
            }
            if (is_array($d)) {
                if (is_array($v)) {
                    $sub = self::sanitize($v, $d);
                    if ($sub !== []) {
                        $out[$k] = $sub;
                    }
                }
                continue;
            }
            if ($d === null) {
                // nullable numeric or date string (bailing_on_hand, trip_cash_target_override, cash_tracking_start)
                if ($v === null || $v === '') {
                    $out[$k] = null;
                } elseif ($k === 'cash_tracking_start') {
                    $ts = strtotime((string) $v);
                    $out[$k] = $ts ? date('Y-m-d', $ts) : null;
                } elseif (is_numeric($v)) {
                    $out[$k] = max(0.0, (float) $v);
                }
                continue;
            }
            if (is_string($d)) {
                $out[$k] = mb_substr((string) $v, 0, 200);
            } elseif (is_numeric($v)) {
                $out[$k] = max(0.0, (float) $v);
            }
        }

        return $out;
    }

    public function save(array $overrides): array
    {
        $merged = self::merge($this->effective(), self::sanitize($overrides));
        Setting::putMany([self::SETTING_KEY => $merged]);

        return $merged;
    }

    public function reset(): array
    {
        Setting::query()->where('key', self::SETTING_KEY)->delete();

        return self::defaults();
    }

    /** Recursive merge where list values (spend_items) replace instead of merging by index. */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if ($k === 'spend_items' && is_array($v)) {
                $base[$k] = array_values(array_map(static fn ($r) => [
                    'label' => (string) ($r['label'] ?? ''),
                    'full' => (float) ($r['full'] ?? 0),
                    'paid' => (float) ($r['paid'] ?? 0),
                    'note' => (string) ($r['note'] ?? ''),
                ], $v));
                continue;
            }
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && self::isAssoc($v)) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }

    private static function isAssoc(array $a): bool
    {
        return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Application retail prices (default price list). Per bale for BOTTLE
     * SKUs, per unit for CONTAINER SKUs.
     *
     * @return list<array<string,mixed>>
     */
    public function appPrices(?array $assumptions = null): array
    {
        $a = $assumptions ?? $this->effective();
        $list = PriceList::query()->where('is_default', true)->first() ?? PriceList::query()->first();
        if (! $list) {
            return [];
        }
        $items = PriceListItem::query()->where('price_list_id', $list->id)->get()->keyBy('sku_id');
        $rows = [];
        foreach (Sku::query()->where('active', true)->orderBy('brand')->orderBy('size_liters')->get() as $sku) {
            $price = (float) optional($items->get($sku->id))->unit_price;
            $size = self::sizeLabel((float) $sku->size_liters);
            if ($price <= 0 || $size === null) {
                continue;
            }
            $unit = strtoupper((string) $sku->unit);
            $bpb = (int) ($a['bottles_per_bale'][$size] ?? 1);
            $isRefill = strtoupper((string) $sku->brand) === 'REFILL' || str_starts_with((string) $sku->code, 'REFILL');
            $perPiece = ($unit === 'BOTTLE' && $bpb > 0) ? $price / $bpb : $price;
            $rows[] = [
                'sku_id' => $sku->id,
                'code' => $sku->code,
                'name' => $sku->name,
                'brand' => $sku->brand ?: 'Mara Water',
                'size' => $size,
                'unit' => $unit,
                'is_refill' => $isRefill,
                'is_custom' => str_contains((string) $sku->code, 'CUSTOM'),
                'sold_as' => $unit === 'BOTTLE' ? "Bale of {$bpb}" : 'Unit',
                'bottles_per_bale' => $unit === 'BOTTLE' ? $bpb : 1,
                'price_per_unit' => round($price, 2),
                'price_per_piece' => round($perPiece, 4),
            ];
        }

        return $rows;
    }

    public static function sizeLabel(float $liters): ?string
    {
        foreach ([0.5 => '0.5L', 1.0 => '1L', 1.5 => '1.5L', 5.0 => '5L', 10.0 => '10L', 20.0 => '20L'] as $v => $label) {
            if (abs($liters - $v) < 0.011) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Pure computation of every sheet.
     *
     * @param  array<string,mixed>  $a  effective assumptions
     * @param  list<array<string,mixed>>  $prices  appPrices()
     * @param  array<string,float>  $liveBailingSheets  size => sheets on hand (live inventory)
     * @return array<string,mixed>
     */
    public function compute(array $a, array $prices, array $liveBailingSheets = []): array
    {
        $vat = (float) $a['vat_rate'];
        $vf = 1 + $vat;
        $tripTotal = (float) array_sum($a['trip_bottles']) ?: 1.0;
        $transportBtl = (float) $a['transport_per_trip'] / $tripTotal;
        $s = $a['seal'];

        // ── Cost Build ────────────────────────────────────────────────
        $cost = [];
        $raw = []; // full-precision figures for downstream maths
        foreach (self::SIZES as $size) {
            $bottled = in_array($size, self::BOTTLED, true);
            $bottleCap = $bottled
                ? ((float) $a['bottle_ex_vat'][$size] + (float) $a['cap_ex_vat']) * $vf
                : (float) ($a['bottle_cap_final'][$size] ?? 0);
            $label = $bottled
                ? (float) $a['label_ex_vat'][$size] * $vf
                : (float) $a['label_large_final'];
            $bpb = (int) ($a['bottles_per_bale'][$size] ?? 1);
            $bailing = (isset($a['bailing_rate_sheet'][$size]) && $bpb > 0)
                ? (float) $a['bailing_rate_sheet'][$size] / $bpb : 0.0;
            $seal = match ($size) {
                '10L' => (float) $s['security'] + (float) $s['topseal'] + (float) $s['security_large'],
                '20L' => (float) $s['topseal'] + (float) $s['security_large'],
                default => (float) $s['security'],
            };
            $materials = $bottleCap + $label + $bailing + $seal;
            $wage = (float) ($a['wage_per_bottle'][$size] ?? 0);
            $transport = ((float) ($a['trip_bottles'][$size] ?? 0)) > 0 ? $transportBtl : 0.0;
            $raw[$size] = ['materials' => $materials, 'cop' => $materials + $wage + $transport];
            $cost[$size] = [
                'size' => $size,
                'bottle_cap' => round($bottleCap, 4),
                'label' => round($label, 4),
                'bailing_paper' => round($bailing, 4),
                'seal' => round($seal, 4),
                'materials' => round($materials, 4),
                'wage' => round($wage, 4),
                'transport' => round($transport, 4),
                'cop' => round($materials + $wage + $transport, 4),
            ];
        }
        $electricity = (float) ($a['overheads']['electricity'] ?? 0);
        $costNote = 'Cost of production per bottle = materials + wage + transport share. '
            .'This is MINUS the electricity cost: monthly electricity (KES '.number_format($electricity)
            .') is an overhead and is not added per bottle.';

        // ── SKU Margins (application prices) ──────────────────────────
        $skuRows = [];
        foreach ($prices as $p) {
            $size = $p['size'];
            $c = $cost[$size] ?? null;
            if (! $c) {
                continue;
            }
            $unitCost = $p['is_refill'] ? 0.0 : (float) $raw[$size]['materials'];
            $fullCost = $p['is_refill'] ? 0.0 : (float) $raw[$size]['cop'];
            $price = (float) $p['price_per_piece'];
            $gross = $price - $unitCost;
            $vatBtl = $gross * ($vat / $vf);
            $skuRows[] = [
                'sku' => $p['name'],
                'code' => $p['code'],
                'brand' => $p['brand'],
                'size' => $size,
                'sold_as' => $p['sold_as'],
                'bale_size' => $p['bottles_per_bale'],
                'price_per_unit' => $p['price_per_unit'],
                'cost_per_bottle' => round($unitCost, 4),
                'price_per_bottle' => round($price, 4),
                'gross_margin' => round($gross, 4),
                'vat_per_bottle' => round($vatBtl, 4),
                'net_margin_vat' => round($gross - $vatBtl, 4),
                'net_margin_non_vat' => round($gross, 4),
                'net_pct_non_vat' => $price > 0 ? round($gross / $price, 4) : 0,
                'margin_after_full_cost' => round($price - $fullCost, 4),
                'full_cost_per_bottle' => round($fullCost, 4),
            ];
        }

        // ── Trip P&L ─────────────────────────────────────────────────
        $premium = [];
        foreach ($prices as $p) {
            if (strcasecmp($p['brand'], 'Premium') === 0 && ! $p['is_custom'] && ! isset($premium[$p['size']])) {
                $premium[$p['size']] = (float) $p['price_per_piece'];
            }
        }
        $tripLines = [];
        $rev = 0.0;
        $costTot = 0.0;
        $qtyTot = 0.0;
        foreach (self::BOTTLED as $size) {
            $qty = (float) ($a['trip_bottles'][$size] ?? 0);
            $c = $cost[$size];
            $price = $premium[$size] ?? 0.0;
            $lr = $qty * $price;
            $lc = $qty * (float) $raw[$size]['cop'];
            $tripLines[] = [
                'sku' => 'Premium '.$size,
                'size' => $size,
                'qty' => $qty,
                'bottle_cap' => $c['bottle_cap'],
                'label' => $c['label'],
                'bailing_paper' => $c['bailing_paper'],
                'seal' => $c['seal'],
                'wage' => $c['wage'],
                'transport' => $c['transport'],
                'cost_per_bottle' => $c['cop'],
                'price_per_bottle' => round($price, 4),
                'revenue' => round($lr, 2),
                'cost_total' => round($lc, 2),
                'gross_margin' => round($lr - $lc, 2),
            ];
            $rev += $lr;
            $costTot += $lc;
            $qtyTot += $qty;
        }
        $gm = $rev - $costTot;
        $vatPayable = -$gm * ($vat / $vf);
        $tripA = [
            'lines' => $tripLines,
            'qty_total' => $qtyTot,
            'revenue' => round($rev, 2),
            'cost_total' => round($costTot, 2),
            'gross_margin' => round($gm, 2),
            'vat_payable' => round($vatPayable, 2),
            'gross_profit_after_vat' => round($gm + $vatPayable, 2),
            'gross_profit_non_vat' => round($gm, 2),
        ];

        $pay = $a['payroll'];
        $overheads = (float) array_sum($a['overheads']);
        $monthly = [];
        for ($t = 1; $t <= 5; $t++) {
            $jim = (float) $a['jim_stipend']["t{$t}"];
            $fixedLow = (float) $pay['accountant_manager'] + (float) $pay['driver_low'] + (float) $pay['sales_low'] + (float) $pay['finalis'] + $jim;
            $fixedHigh = (float) $pay['accountant_manager'] + (float) $pay['driver_high'] + (float) $pay['sales_high'] + (float) $pay['finalis'] + $jim;
            $revT = $rev * $t;
            $costT = $costTot * $t;
            $gmT = $revT - $costT;
            $vatT = -$gmT * ($vat / $vf);
            $monthly[] = [
                'trips' => $t,
                'bottled_revenue' => round($revT, 2),
                'total_revenue' => round($revT, 2),
                'cost_of_production' => round($costT, 2),
                'gross_margin' => round($gmT, 2),
                'vat_payable' => round($vatT, 2),
                'gross_profit_after_vat' => round($gmT + $vatT, 2),
                'jim_stipend' => $jim,
                'fixed_payroll_low' => round($fixedLow, 2),
                'fixed_payroll_high' => round($fixedHigh, 2),
                'overheads' => round($overheads, 2),
                'net_vat_low' => round($gmT + $vatT - $fixedLow - $overheads, 2),
                'net_vat_high' => round($gmT + $vatT - $fixedHigh - $overheads, 2),
                'net_non_vat_low' => round($gmT - $fixedLow - $overheads, 2),
                'net_non_vat_high' => round($gmT - $fixedHigh - $overheads, 2),
            ];
        }

        // ── Profit Split ─────────────────────────────────────────────
        $basis = ($a['payroll_basis'] ?? 'high') === 'low' ? 'net_non_vat_low' : 'net_non_vat_high';
        $ps = $a['profit_split'];
        $derrickTotal = (float) $a['capital']['derrick_given'] + (float) $a['capital']['derrick_pending'];
        $partners = array_keys($ps['phase1_one']);
        $phase1 = [];
        $phase1x2 = [];
        $phase2 = [];
        foreach ($partners as $name) {
            $r1 = [];
            $r1x2 = [];
            $r2 = [];
            foreach ($monthly as $i => $m) {
                $net = (float) $m[$basis];
                $pct1 = $m['trips'] === 1 ? (float) $ps['phase1_one'][$name] : (float) $ps['phase1_multi'][$name];
                $r1[] = round($net * $pct1, 2);
                $r1x2[] = round($net * $pct1 * 2, 2);
                $r2[] = round($net * (float) $ps['phase2'][$name], 2);
            }
            $phase1[$name] = $r1;
            $phase1x2[$name] = $r1x2;
            $phase2[$name] = $r2;
        }
        $netRow = array_map(static fn ($m) => (float) $m[$basis], $monthly);
        $derrickRecv = $phase1x2['Derrick'] ?? [0, 0, 0, 0, 0];
        $derrickPct = array_map(static fn ($v) => $derrickTotal > 0 ? round($v / $derrickTotal, 4) : 0, $derrickRecv);
        $remaining = array_map(static fn ($v) => round($derrickTotal - $v, 2), $derrickRecv);
        $monthsNeeded = [];
        foreach ($remaining as $i => $rem) {
            $rate = $phase2['Derrick'][$i] ?? 0;
            $monthsNeeded[] = $rate > 0 ? round(max(0, $rem) / $rate, 2) : null;
        }
        $profitSplit = [
            'basis' => $basis,
            'basis_label' => $basis === 'net_non_vat_low' ? 'Non-VAT, low payroll (driver/sales base)' : 'Non-VAT, high payroll (driver/sales with bonuses ≈ 25k)',
            'net_profit' => array_map(static fn ($v) => round($v, 2), $netRow),
            'partners' => $partners,
            'phase1_monthly' => $phase1,
            'phase1_two_month' => $phase1x2,
            'phase2_monthly' => $phase2,
            'derrick' => [
                'total_committed' => $derrickTotal,
                'received_nov_dec' => $derrickRecv,
                'pct_recovered' => $derrickPct,
                'remaining_after_nov_dec' => $remaining,
                'months_needed_phase2' => $monthsNeeded,
            ],
            'split_sums' => [
                'phase1_one' => round((float) array_sum($ps['phase1_one']), 4),
                'phase1_multi' => round((float) array_sum($ps['phase1_multi']), 4),
                'phase2' => round((float) array_sum($ps['phase2']), 4),
            ],
        ];

        // ── Funding & Startup ────────────────────────────────────────
        $spend = $a['spend_items'];
        $spendFull = (float) array_sum(array_column($spend, 'full'));
        $spendPaid = (float) array_sum(array_column($spend, 'paid'));
        $loanInterest = (float) $a['loan']['principal'] * (float) $a['loan']['rate'];
        $capReceived = (float) $a['capital']['diana_onroy'] + (float) $a['capital']['derrick_given'];
        $funding = $capReceived + (float) $a['capital']['derrick_pending'] + (float) $a['loan']['principal'];
        $buffer = $funding - $spendFull;

        $bailing = [];
        $bailTotal = 0.0;
        $spb = (int) $a['sheets_per_bag'];
        foreach (['0.5L', '1L', '1.5L', '5L'] as $size) {
            $bpb = (int) $a['bottles_per_bale'][$size];
            $need = $bpb > 0 ? (int) ceil(((float) ($a['trip_bottles'][$size] ?? 0)) / $bpb) : 0;
            $manual = $a['bailing_on_hand'][$size] ?? null;
            $onHand = $manual !== null ? (float) $manual : (float) ($liveBailingSheets[$size] ?? 0);
            $deficit = max(0.0, $need - $onHand);
            $bags = $spb > 0 ? (int) ceil($deficit / $spb) : 0;
            $rate = (float) ($a['bailing_rate_sheet'][$size] ?? 0);
            $cst = $bags * $spb * $rate;
            $bailTotal += $cst;
            $bailing[] = [
                'size' => $size,
                'need_sheets' => $need,
                'on_hand_sheets' => $onHand,
                'on_hand_source' => $manual !== null ? 'manual' : 'live',
                'deficit_sheets' => $deficit,
                'bags_needed' => $bags,
                'rate_per_sheet' => $rate,
                'cost' => round($cst, 2),
            ];
        }
        $m1 = $monthly[0];
        $fundingSheet = [
            'spend_items' => $spend,
            'spend_full' => round($spendFull, 2),
            'spend_paid' => round($spendPaid, 2),
            'retainage_pending' => round($spendFull - $spendPaid, 2),
            'loan' => [
                'principal' => (float) $a['loan']['principal'],
                'rate' => (float) $a['loan']['rate'],
                'interest' => round($loanInterest, 2),
                'total_repayment' => round((float) $a['loan']['principal'] + $loanInterest, 2),
                'due' => $a['loan']['due'],
            ],
            'reconciliation' => [
                'capital_received' => round($capReceived, 2),
                'capital_pending' => (float) $a['capital']['derrick_pending'],
                'loan' => (float) $a['loan']['principal'],
                'total_funding' => round($funding, 2),
                'total_spend' => round($spendFull, 2),
                'buffer' => round($buffer, 2),
            ],
            'bailing_paper' => ['rows' => $bailing, 'total' => round($bailTotal, 2)],
            'startup' => [
                'bailing_deficit' => round($bailTotal, 2),
                'buffer_remaining' => round($buffer, 2),
                'shortfall_or_surplus' => round($buffer - $bailTotal, 2),
                'month_fixed_low' => $m1['fixed_payroll_low'] + $m1['overheads'],
                'month_fixed_high' => $m1['fixed_payroll_high'] + $m1['overheads'],
            ],
        ];

        return [
            'cost_build' => ['rows' => array_values($cost), 'note' => $costNote, 'transport_per_bottle' => round($transportBtl, 4), 'trip_bottles_total' => $tripTotal],
            'sku_margins' => $skuRows,
            'trip_pl' => ['a' => $tripA, 'b' => $monthly],
            'profit_split' => $profitSplit,
            'funding' => $fundingSheet,
            'prices_source' => 'Application default retail price list',
        ];
    }

    /** Full payload for the API. */
    public function snapshot(?array $assumptions = null): array
    {
        $a = $assumptions ?? $this->effective();
        $prices = $this->appPrices($a);
        $live = $this->liveBailingSheets();

        return [
            'assumptions' => $a,
            'defaults' => self::defaults(),
            'app_prices' => $prices,
            'computed' => $this->compute($a, $prices, $live),
        ];
    }

    /** @return array<string,float> sheets on hand per size from live inventory */
    public function liveBailingSheets(): array
    {
        $out = [];
        foreach (['0.5L', '1L', '1.5L', '5L'] as $size) {
            $out[$size] = $this->materialQty('BAILING-'.$size);
        }

        return $out;
    }

    public function materialQty(string $code): float
    {
        $id = \Illuminate\Support\Facades\DB::table('materials')->where('code', $code)->whereNull('deleted_at')->value('id');
        if (! $id) {
            return 0.0;
        }

        return (float) \Illuminate\Support\Facades\DB::table('stock_items')
            ->where('item_type', 'material')->where('material_id', $id)->whereNull('deleted_at')->sum('qty');
    }
}
