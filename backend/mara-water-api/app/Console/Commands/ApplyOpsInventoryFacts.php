<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InventoryController;
use App\Models\Batch;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Set unfinished-materials on hand from the counted opening stock,
 * dated receipts, testing damage, and bales already produced.
 * Does not rewrite sales or production batch records.
 */
class ApplyOpsInventoryFacts extends Command
{
    protected $signature = 'inventory:apply-ops-facts';

    protected $description = 'Reconcile raw-material on-hand to counted opening stock minus production used';

    public function handle(): int
    {
        $main = Warehouse::where('code', 'MAIN')->first() ?? Warehouse::orderBy('name')->first();
        $cold = Warehouse::where('code', 'COLD')->first();
        if (! $main) {
            $this->error('No warehouse.');

            return 1;
        }

        $director = User::whereHas('role', fn ($q) => $q->where('access_tier', 'director'))->first();
        if ($director) {
            Auth::loginUsingId($director->id);
        }

        $used = $this->producedUsage();
        $this->info(sprintf(
            'Produced bales — 0.5L %s, 1L %s, 1.5L %s, 5L %s',
            $used['bales_0_5'],
            $used['bales_1'],
            $used['bales_1_5'],
            $used['bales_5']
        ));

        $targets = $this->targets($used);
        $mins = [
            'BOTTLE-5L' => 8,
            'BAILING-1L' => 80,
            'BAILING-5L' => 40,
            'BOTTLE-1L' => 240,
            'LABEL-0.5L' => 500,
            'LABEL-1L' => 500,
            'LABEL-1.5L' => 500,
            'LABEL-5L' => 200,
            'SEAL-BOTTLE' => 1000,
            'STAT-RECEIPT' => 2,
            'STAT-DELIVERY' => 2,
            'STAT-INVOICE' => 2,
            'PPE-GUNBOOT' => 1,
            'PPE-RAINCOAT' => 1,
        ];

        DB::transaction(function () use ($main, $cold, $targets, $mins, $director) {
            $this->ensureExtraMaterials($director?->id);
            $this->patchBom();

            $inventory = new InventoryController();
            $floors = [
                'LABEL-10L' => 20000,
                'LABEL-20L' => 20000,
                'SEAL-REFILL' => 20000,
                'CAP-10L' => 20000,
                'CAP-20L' => 20000,
                'CAP-5L' => 20000,
            ];
            foreach ($floors as $code => $floor) {
                $mat = Material::where('code', $code)->first();
                if (! $mat) {
                    continue;
                }
                $current = (float) (StockItem::where('item_type', 'material')
                    ->where('material_id', $mat->id)
                    ->where('warehouse_id', $main->id)
                    ->whereNull('deleted_at')
                    ->sum('qty'));
                $targets[$code] = max($current, (float) $floor);
                $mat->min_level = 0;
                $mat->save();
            }

            foreach ($targets as $code => $qty) {
                $mat = Material::where('code', $code)->first();
                if (! $mat) {
                    $this->warn("Missing material {$code}");
                    continue;
                }
                if (isset($mins[$code])) {
                    $mat->min_level = $mins[$code];
                    $mat->save();
                }
                if ($cold) {
                    $this->setQty($inventory, $mat, $cold->id, 0, $director?->id);
                }
                $this->setQty($inventory, $mat, $main->id, $qty, $director?->id);
                $this->balanceBatch($mat, $main->id, $qty, $director?->id);
                $this->line(sprintf('  %s → %s', $code, number_format($qty)));
            }

            $this->documentReceipts($main->id, $director?->id);
        });

        $this->info('Inventory on-hand now matches the counted facts less production used.');

        return 0;
    }

    /** @return array{bales_0_5:float,bales_1:float,bales_1_5:float,bales_5:float,bottles_0_5:float,bottles_1:float,bottles_1_5:float,bottles_5:float} */
    private function producedUsage(): array
    {
        $rows = Batch::query()
            ->whereNull('batches.deleted_at')
            ->join('skus', 'skus.id', '=', 'batches.sku_id')
            ->selectRaw('skus.code as code, SUM(COALESCE(batches.actual_qty, batches.planned_qty)) as bales')
            ->groupBy('skus.code')
            ->pluck('bales', 'code');

        $b05 = (float) ($rows['PREM-0.5L'] ?? 0) + (float) ($rows['PREM-CUSTOM-0.5L'] ?? 0);
        $b1 = (float) ($rows['PREM-1L'] ?? 0);
        $b15 = (float) ($rows['PREM-1.5L'] ?? 0);
        $b5 = (float) ($rows['PREM-5L'] ?? 0);

        // Physical pack: 24×0.5L, 12×1L, 12×1.5L, 4×5L. Do not use a
        // mis-keyed config lookup (1.5L was being treated as 24).
        $p05 = 24;
        $p1 = 12;
        $p15 = 12;
        $p5 = 4;

        return [
            'bales_0_5' => $b05,
            'bales_1' => $b1,
            'bales_1_5' => $b15,
            'bales_5' => $b5,
            'bottles_0_5' => $b05 * $p05,
            'bottles_1' => $b1 * $p1,
            'bottles_1_5' => $b15 * $p15,
            'bottles_5' => $b5 * $p5,
        ];
    }

    /** @param  array<string,float>  $u */
    private function targets(array $u): array
    {
        $smallBottles = $u['bottles_0_5'] + $u['bottles_1'] + $u['bottles_1_5'];

        return [
            // Bottles: opening + Friday 10 October 2026 receipt − machine-test damage − produced.
            'BOTTLE-0.5L' => 23800 - 6 - $u['bottles_0_5'],
            'BOTTLE-1L' => 2640 - 1 - $u['bottles_1'],
            'BOTTLE-1.5L' => 5712 - $u['bottles_1_5'],
            'BOTTLE-5L' => 41 - $u['bottles_5'],
            // Labels held before Monday 26 October 2026, plus Sunday 1 November 2026 receipts.
            'LABEL-0.5L' => 1005 + 50000 - $u['bottles_0_5'],
            'LABEL-1L' => 10000 - $u['bottles_1'],
            'LABEL-1.5L' => 3000 + 30000 - $u['bottles_1_5'],
            'LABEL-5L' => 2500 - $u['bottles_5'],
            'LABEL-PLAT-0.5L' => 1000,
            'LABEL-PLAT-1L' => 27000,
            'LABEL-10L' => 20000,
            'LABEL-20L' => 20000,
            // Bailing sheets. 1.5L bales have always used the 5L sheets.
            'BAILING-0.5L' => 2500 - $u['bales_0_5'],
            'BAILING-1L' => 250 - $u['bales_1'],
            'BAILING-1.5L' => 0,
            'BAILING-5L' => 500 - $u['bales_5'] - $u['bales_1_5'],
            // Caps and seals counted for 0.5L / 1L / 1.5L.
            'CAP-28MM-DARK' => 32152 - $smallBottles,
            'SEAL-BOTTLE' => 15000 - $smallBottles,
            // Refill jerricans were plentiful. 10L/20L labels, caps and seals
            // are raised only if below this floor so an existing high count
            // is not cut.
            'BOTTLE-10L' => 2000,
            'BOTTLE-20L' => 2000,
            'STAT-RECEIPT' => 8,
            'STAT-DELIVERY' => 18,
            'STAT-INVOICE' => 15,
            'PPE-GUNBOOT' => 4,
            'PPE-RAINCOAT' => 3,
            'EQUIP-HEAT-GUN' => 4,
            'EQUIP-BATCHING' => 1,
            // Exact pH-tester count was not given; recorded as on hand.
            'LAB-PH-TESTER' => 1,
        ];
    }

    private function ensureExtraMaterials(?string $actorId): void
    {
        $defs = [
            ['code' => 'LABEL-PLAT-0.5L', 'name' => 'Platinum 0.5L Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-PLAT-1L', 'name' => 'Platinum 1L Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'EQUIP-HEAT-GUN', 'name' => 'Heat Guns', 'category' => 'Equipment', 'uom' => 'PCS'],
            ['code' => 'EQUIP-BATCHING', 'name' => 'Batching Machine', 'category' => 'Equipment', 'uom' => 'PCS'],
            ['code' => 'LAB-PH-TESTER', 'name' => 'pH Testers', 'category' => 'Equipment', 'uom' => 'PCS'],
        ];
        foreach ($defs as $def) {
            Material::firstOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'category' => $def['category'],
                    'uom' => $def['uom'],
                    'is_consumable' => $def['category'] === 'Packaging',
                    'min_level' => 0,
                    'unit_cost' => 0,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]
            );
        }
    }

    private function patchBom(): void
    {
        $perBale = [0.5 => 24, 1.0 => 12, 1.5 => 12, 5.0 => 4];
        $bailing5 = Material::where('code', 'BAILING-5L')->first();
        $capPool = Material::where('code', 'CAP-28MM-DARK')->first();

        foreach (Sku::whereIn('size_liters', array_keys($perBale))->get() as $sku) {
            $size = round((float) $sku->size_liters, 2);
            $bpb = $perBale[$size] ?? null;
            if (! $bpb) {
                continue;
            }
            $brand = strtolower((string) $sku->brand);
            $sizeCode = $sku->sizeCode();
            $labelCode = str_contains($brand, 'platinum') ? "LABEL-PLAT-{$sizeCode}" : "LABEL-{$sizeCode}";
            $bailingCode = $size === 1.5 ? 'BAILING-5L' : "BAILING-{$sizeCode}";
            $capCode = in_array($size, [0.5, 1.0, 1.5], true) ? 'CAP-28MM-DARK' : "CAP-{$sizeCode}";

            if ($size === 1.5 && $bailing5) {
                $old = Material::where('code', 'BAILING-1.5L')->first();
                if ($old) {
                    BomItem::where('sku_id', $sku->id)->where('material_id', $old->id)->update([
                        'material_id' => $bailing5->id,
                    ]);
                }
            }

            if (str_contains($brand, 'platinum')) {
                $plain = Material::where('code', "LABEL-{$sizeCode}")->first();
                $plat = Material::where('code', $labelCode)->first();
                if ($plain && $plat) {
                    BomItem::where('sku_id', $sku->id)->where('material_id', $plain->id)->update([
                        'material_id' => $plat->id,
                    ]);
                }
            }

            if ($capPool && in_array($size, [0.5, 1.0, 1.5], true)) {
                $sizeCap = Material::where('code', "CAP-{$sizeCode}")->first();
                if ($sizeCap) {
                    BomItem::where('sku_id', $sku->id)->where('material_id', $sizeCap->id)->update([
                        'material_id' => $capPool->id,
                    ]);
                }
            }

            foreach (["BOTTLE-{$sizeCode}" => $bpb, $labelCode => $bpb, $capCode => $bpb, 'SEAL-BOTTLE' => $bpb, $bailingCode => 1] as $code => $qty) {
                $mat = Material::where('code', $code)->first();
                if (! $mat) {
                    continue;
                }
                $line = BomItem::where('sku_id', $sku->id)->where('material_id', $mat->id)->first();
                if ($line) {
                    $line->update(['qty_per_unit' => $qty, 'uom' => 'PCS']);
                } else {
                    BomItem::create([
                        'sku_id' => $sku->id,
                        'material_id' => $mat->id,
                        'qty_per_unit' => $qty,
                        'uom' => 'PCS',
                    ]);
                }
            }
        }
    }

    private function setQty(InventoryController $inventory, Material $material, string $warehouseId, float $target, ?string $actorId): void
    {
        $current = (float) (StockItem::where('item_type', 'material')
            ->where('material_id', $material->id)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->sum('qty'));

        $delta = round($target - $current, 3);
        if (abs($delta) < 0.001) {
            return;
        }

        if ($delta > 0) {
            $inventory->recordMove([
                'move_type' => 'grn',
                'item_type' => 'material',
                'material_id' => $material->id,
                'warehouse_to_id' => $warehouseId,
                'qty' => $delta,
                'uom' => $material->uom ?: 'PCS',
                'ref_entity' => 'ops_inventory_facts',
                'ref_id' => $material->id,
            ], allowNegative: true);
        } else {
            $inventory->recordMove([
                'move_type' => 'adjust',
                'item_type' => 'material',
                'material_id' => $material->id,
                'warehouse_from_id' => $warehouseId,
                'qty' => abs($delta),
                'uom' => $material->uom ?: 'PCS',
                'ref_entity' => 'ops_inventory_facts',
                'ref_id' => $material->id,
            ], allowNegative: true);
        }
    }

    private function balanceBatch(Material $material, string $warehouseId, float $qty, ?string $actorId): void
    {
        MaterialBatch::where('material_id', $material->id)
            ->where('warehouse_id', $warehouseId)
            ->where('batch_number', 'not like', 'OPS-FACTS-BAL-%')
            ->update(['qty_remaining' => 0]);

        $number = 'OPS-FACTS-BAL-'.$material->code;
        $notes = 'On hand after bales produced since operations began on Monday, 26 October 2026. '
            .'Opening counts, the Friday, 10 October 2026 bottle and cap receipt, machine-test damage, '
            .'and the Sunday, 1 November 2026 label receipt are already included.';

        $batch = MaterialBatch::where('batch_number', $number)->first();
        $payload = [
            'material_id' => $material->id,
            'batch_number' => $number,
            'arrival_type' => 'opening_stock',
            'purchase_date' => '2026-11-04',
            'supplier_name' => 'Warehouse count',
            'supplier_code' => 'OPS',
            'qty_received' => max(0, $qty),
            'qty_remaining' => max(0, $qty),
            'package_unit' => 'pcs',
            'warehouse_id' => $warehouseId,
            'received_by' => $actorId,
            'notes' => $notes,
            'quality_status' => 'passed',
            'quality_checked_at' => now(),
            'quality_checked_by' => $actorId,
        ];
        if ($batch) {
            $batch->update($payload);
        } else {
            MaterialBatch::create($payload);
        }
    }

    private function documentReceipts(string $warehouseId, ?string $actorId): void
    {
        $docs = [
            ['LABEL-1L', 'OPS-FACTS-OPEN-LABEL-1L', '2026-10-09', 10000, 'Held before operations began on Monday, 26 October 2026 — 10,000 labels of 1L.'],
            ['LABEL-1.5L', 'OPS-FACTS-OPEN-LABEL-1.5L', '2026-10-09', 3000, 'Held before operations began on Monday, 26 October 2026 — 3,000 labels of 1.5L.'],
            ['LABEL-0.5L', 'OPS-FACTS-OPEN-LABEL-0.5L', '2026-10-09', 1005, 'Held before operations began on Monday, 26 October 2026 — 1,005 labels of 0.5L.'],
            ['LABEL-PLAT-1L', 'OPS-FACTS-OPEN-PLAT-1L', '2026-10-09', 27000, 'Held before operations began on Monday, 26 October 2026 — 27,000 Platinum 1L labels.'],
            ['LABEL-PLAT-0.5L', 'OPS-FACTS-OPEN-PLAT-0.5L', '2026-10-09', 1000, 'Held before operations began on Monday, 26 October 2026 — 1,000 Platinum 0.5L labels.'],
            ['LABEL-5L', 'OPS-FACTS-OPEN-LABEL-5L', '2026-10-09', 2500, 'Held before operations began on Monday, 26 October 2026 — 2,500 labels of 5L.'],
            ['SEAL-BOTTLE', 'OPS-FACTS-OPEN-SEAL', '2026-10-09', 15000, 'Held before operations began on Monday, 26 October 2026 — 15,000 top seals for 0.5L, 1L and 1.5L.'],
            ['BOTTLE-5L', 'OPS-FACTS-OPEN-BOTTLE-5L', '2026-10-09', 41, 'Held before operations began on Monday, 26 October 2026 — 41 bottles of 5L.'],
            ['BAILING-0.5L', 'OPS-FACTS-OPEN-BAIL-0.5', '2026-10-09', 2500, 'Held before operations began on Monday, 26 October 2026 — 2,500 pieces of 0.5L bailing paper (5 bags).'],
            ['BAILING-1L', 'OPS-FACTS-OPEN-BAIL-1', '2026-10-09', 250, 'Held before operations began on Monday, 26 October 2026 — 250 pieces of 1L bailing paper (half a bag).'],
            ['BAILING-5L', 'OPS-FACTS-OPEN-BAIL-5', '2026-10-09', 500, 'Held before operations began on Monday, 26 October 2026 — 500 pieces of 5L bailing paper (1 bag). 1.5L bales use these sheets. About 10 pieces wrapped the 10 bales of 5L; the rest went to 1.5L.'],
            ['BOTTLE-0.5L', 'OPS-FACTS-RCPT-B05', '2026-10-10', 23800, 'Friday, 10 October 2026 — received 23,800 bottles of 0.5L.'],
            ['BOTTLE-1L', 'OPS-FACTS-RCPT-B1', '2026-10-10', 2640, 'Friday, 10 October 2026 — received 2,640 bottles of 1L.'],
            ['BOTTLE-1.5L', 'OPS-FACTS-RCPT-B15', '2026-10-10', 5712, 'Friday, 10 October 2026 — received 5,712 bottles of 1.5L.'],
            ['CAP-28MM-DARK', 'OPS-FACTS-RCPT-CAPS', '2026-10-10', 32152, 'Friday, 10 October 2026 — received 32,152 top caps for 0.5L, 1L and 1.5L.'],
            ['BOTTLE-0.5L', 'OPS-FACTS-DMG-B05', '2026-10-20', 6, 'Machine testing before Monday, 26 October 2026 — 6 bottles of 0.5L damaged.'],
            ['BOTTLE-1L', 'OPS-FACTS-DMG-B1', '2026-10-20', 1, 'Machine testing before Monday, 26 October 2026 — 1 bottle of 1L damaged.'],
            ['LABEL-0.5L', 'OPS-FACTS-RCPT-L05', '2026-11-01', 50000, 'Sunday, 1 November 2026 — received 50,000 labels of 0.5L.'],
            ['LABEL-1.5L', 'OPS-FACTS-RCPT-L15', '2026-11-01', 30000, 'Sunday, 1 November 2026 — received 30,000 labels of 1.5L.'],
            ['STAT-RECEIPT', 'OPS-FACTS-OPEN-RCPT', '2026-10-09', 8, 'Held before operations began on Monday, 26 October 2026 — 8 receipt books.'],
            ['STAT-DELIVERY', 'OPS-FACTS-OPEN-DEL', '2026-10-09', 18, 'Held before operations began on Monday, 26 October 2026 — 18 delivery books.'],
            ['STAT-INVOICE', 'OPS-FACTS-OPEN-INV', '2026-10-09', 15, 'Held before operations began on Monday, 26 October 2026 — 15 invoice books.'],
            ['PPE-GUNBOOT', 'OPS-FACTS-OPEN-BOOT', '2026-10-09', 4, 'Held before operations began on Monday, 26 October 2026 — 4 gumboots.'],
            ['PPE-RAINCOAT', 'OPS-FACTS-OPEN-COAT', '2026-10-09', 3, 'Held before operations began on Monday, 26 October 2026 — 3 pairs of raincoats.'],
            ['EQUIP-HEAT-GUN', 'OPS-FACTS-OPEN-GUN', '2026-10-09', 4, 'Held before operations began on Monday, 26 October 2026 — 4 heat guns, including the 2 new ones.'],
            ['EQUIP-BATCHING', 'OPS-FACTS-OPEN-BATCH', '2026-10-09', 1, 'Held before operations began on Monday, 26 October 2026 — 1 batching machine.'],
            ['LAB-PH-TESTER', 'OPS-FACTS-OPEN-PH', '2026-10-09', 1, 'Held before operations began on Monday, 26 October 2026 — pH testers on hand. Exact count was not stated; recorded as 1 until confirmed.'],
        ];

        foreach ($docs as [$code, $number, $date, $qty, $notes]) {
            if (MaterialBatch::where('batch_number', $number)->exists()) {
                continue;
            }
            $mat = Material::where('code', $code)->first();
            if (! $mat) {
                continue;
            }
            MaterialBatch::create([
                'material_id' => $mat->id,
                'batch_number' => $number,
                'arrival_type' => str_contains($number, 'DMG') ? 'adjustment' : (str_contains($number, 'RCPT') ? 'purchase' : 'opening_stock'),
                'purchase_date' => $date,
                'supplier_name' => 'Ops count',
                'supplier_code' => 'OPS',
                'qty_received' => $qty,
                'qty_remaining' => 0,
                'package_unit' => 'pcs',
                'warehouse_id' => $warehouseId,
                'received_by' => $actorId,
                'notes' => $notes,
                'quality_status' => 'passed',
                'quality_checked_at' => now(),
                'quality_checked_by' => $actorId,
                'quality_notes' => 'Record only — on-hand is the balance batch, so this line is not counted twice.',
            ]);
        }
    }
}
