<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Warehouse;

/**
 * How many bales can still be produced from on-hand packaging materials.
 *
 * Per bottle produced: 1 bottle + 1 label + 1 cap + 1 seal.
 * Per bale: bottles_per_bale of each of those, plus 1 bailing sheet
 * (0.5L / 1L / 1.5L / 5L only — 10L and 20L are not baled).
 *
 * Binding constraint = min(bottles, labels, caps, seals, bailing) in bales.
 */
class ProductionCapacityService
{
    /**
     * @return array{rows: list<array<string,mixed>>, alerts: list<array<string,mixed>>, summary: array<string,mixed>}
     */
    public function snapshot(?string $warehouseId = null): array
    {
        $warehouseId = $warehouseId
            ?: Warehouse::whereIn('code', ['MAIN', 'WH-MAIN'])->orderBy('code')->value('id')
            ?: Warehouse::orderBy('name')->value('id');

        $rows = $this->bySize($warehouseId);
        $alerts = $this->buildAlerts($rows);

        $totalBalesLeft = array_sum(array_map(fn ($r) => (float) $r['bales_possible'], $rows));
        $blocked = array_values(array_filter($rows, fn ($r) => (float) $r['bales_possible'] <= 0));

        return [
            'rows' => $rows,
            'alerts' => $alerts,
            'summary' => [
                'warehouse_id' => $warehouseId,
                'total_bales_possible' => round($totalBalesLeft, 2),
                'sizes_blocked' => count($blocked),
                'sizes_tracked' => count($rows),
                'rules' => [
                    'per_bottle' => '1 bottle + 1 label + 1 cap + 1 top seal',
                    'bailing' => '1 sheet = 1 bale (24×0.5L, 12×1L, 12×1.5L, 4×5L); none for 10L/20L',
                    'note' => 'Pending purchase orders are not counted until stock is received.',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function bySize(?string $warehouseId = null): array
    {
        $warehouseId = $warehouseId
            ?: Warehouse::whereIn('code', ['MAIN', 'WH-MAIN'])->orderBy('code')->value('id')
            ?: Warehouse::orderBy('name')->value('id');

        $sizes = [0.5, 1.0, 1.5, 5.0, 10.0, 20.0];
        $rows = [];

        foreach ($sizes as $size) {
            $sku = new Sku(['size_liters' => $size]);
            $sizeCode = $sku->sizeCode();
            $bpb = $sku->bottlesPerBale();
            $usesBailing = $sku->usesBailingPaper();

            $bottles = $this->materialQty("BOTTLE-{$sizeCode}", $warehouseId);
            $labels = $this->materialQty("LABEL-{$sizeCode}", $warehouseId);
            // 0.5 / 1 / 1.5 share Intripet CAP-28MM-DARK (same pool BOM deducts).
            $caps = in_array($size, [0.5, 1.0, 1.5], true)
                ? $this->materialQty('CAP-28MM-DARK', $warehouseId)
                : $this->materialQty("CAP-{$sizeCode}", $warehouseId);
            $seals = $this->materialQty('SEAL-BOTTLE', $warehouseId);
            $bailing = $usesBailing ? $this->materialQty("BAILING-{$sizeCode}", $warehouseId) : null;

            $fromBottles = $bpb > 0 ? $bottles / $bpb : 0;
            $fromLabels = $bpb > 0 ? $labels / $bpb : 0;
            $fromCaps = $bpb > 0 ? $caps / $bpb : 0;
            $fromSeals = $bpb > 0 ? $seals / $bpb : 0;
            // 1 bailing sheet wraps exactly one finished bale.
            $fromBailing = $usesBailing ? (float) $bailing : null;

            $candidates = [
                'Bottles' => $fromBottles,
                'Labels' => $fromLabels,
                'Caps' => $fromCaps,
                'Seals' => $fromSeals,
            ];
            if ($usesBailing) {
                $candidates['Bailing paper'] = $fromBailing ?? 0;
            }

            $bindingName = '—';
            $balesPossible = PHP_FLOAT_MAX;
            foreach ($candidates as $name => $val) {
                if ($val < $balesPossible) {
                    $balesPossible = $val;
                    $bindingName = $name;
                }
            }
            if ($balesPossible === PHP_FLOAT_MAX) {
                $balesPossible = 0;
            }

            $balesPossible = max(0, $balesPossible);

            $rows[] = [
                'size' => $sizeCode,
                'size_liters' => $size,
                'bottles_per_bale' => $bpb,
                'uses_bailing' => $usesBailing,
                'labels_on_hand' => round($labels, 1),
                'bailing_on_hand' => $usesBailing ? round((float) $bailing, 1) : null,
                'bailing_display' => $usesBailing
                    ? ($bailing > 0 ? round((float) $bailing, 1) . ' sheets' : '0')
                    : 'Not baled',
                'bottles_on_hand' => round($bottles, 1),
                'caps_on_hand' => round($caps, 1),
                'seals_on_hand' => round($seals, 1),
                'from_bottles_bales' => round(max(0, $fromBottles), 2),
                'from_labels_bales' => round(max(0, $fromLabels), 2),
                'from_caps_bales' => round(max(0, $fromCaps), 2),
                'from_seals_bales' => round(max(0, $fromSeals), 2),
                'from_bailing_bales' => $usesBailing ? round(max(0, (float) $fromBailing), 2) : null,
                'binding_constraint' => $bindingName,
                'bales_possible' => round($balesPossible, 2),
                'bottles_possible' => (int) floor($balesPossible * $bpb),
                'production_left' => [
                    'bales' => round($balesPossible, 2),
                    'bottles' => (int) floor($balesPossible * $bpb),
                    'until' => $bindingName === '—' ? null : strtolower($bindingName) . ' depletes',
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function buildAlerts(array $rows): array
    {
        $alerts = [];

        foreach ($rows as $row) {
            $size = $row['size'];
            $bales = (float) $row['bales_possible'];
            $binding = $row['binding_constraint'];

            if ($bales <= 0) {
                $alerts[] = [
                    'level' => 'critical',
                    'size' => $size,
                    'code' => 'blocked',
                    'message' => "{$size}: cannot produce — {$binding} depleted (0 bales left).",
                    'bales_left' => 0,
                    'stock' => $this->stockSnippet($row),
                ];
                continue;
            }

            if ($bales < 20) {
                $alerts[] = [
                    'level' => 'warning',
                    'size' => $size,
                    'code' => 'low_runway',
                    'message' => "{$size}: only {$bales} bales left until {$binding} runs out.",
                    'bales_left' => $bales,
                    'stock' => $this->stockSnippet($row),
                ];
            }

            if ($row['uses_bailing'] && (float) ($row['bailing_on_hand'] ?? 0) <= 0) {
                $alerts[] = [
                    'level' => 'critical',
                    'size' => $size,
                    'code' => 'no_bailing',
                    'message' => "{$size}: no bailing paper on hand.",
                    'bales_left' => $bales,
                    'stock' => $this->stockSnippet($row),
                ];
            }

            if ((float) $row['labels_on_hand'] <= 0) {
                $alerts[] = [
                    'level' => 'critical',
                    'size' => $size,
                    'code' => 'no_labels',
                    'message' => "{$size}: no labels on hand.",
                    'bales_left' => $bales,
                    'stock' => $this->stockSnippet($row),
                ];
            }
        }

        // Deduplicate identical criticals that overlap low_runway.
        $seen = [];
        $unique = [];
        foreach ($alerts as $alert) {
            $key = $alert['size'] . '|' . $alert['code'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $alert;
        }

        usort($unique, function ($a, $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9);
        });

        return $unique;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function stockSnippet(array $row): array
    {
        return [
            'labels' => $row['labels_on_hand'],
            'bailing' => $row['bailing_on_hand'],
            'bottles' => $row['bottles_on_hand'],
            'caps' => $row['caps_on_hand'],
            'seals' => $row['seals_on_hand'],
        ];
    }

    private function materialQty(string $code, ?string $warehouseId): float
    {
        $materialId = Material::where('code', $code)->whereNull('deleted_at')->value('id');
        if (! $materialId || ! $warehouseId) {
            return 0.0;
        }

        return (float) (StockItem::where('item_type', 'material')
            ->where('material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->value('qty') ?? 0);
    }
}
