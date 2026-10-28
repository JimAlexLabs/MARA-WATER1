<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InventoryController;
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
 * Wire production packaging automation:
 * - per-size label / bailing / bottle / cap / seal materials
 * - BOM per bale (production unit): N bottles+labels+caps+seals, 1 bailing sheet
 * - opening stock for labels, bailing, high caps/seals
 *
 * Opening figures (ops, Oct 2026):
 * Labels: 0.5L = 41 bales × 24 = 984; 1L = 10,000; 1.5L = 3,000; 5L = 2,500
 * Bailing sheets: 0.5L = 2,000 (4 bags); 1L = 250; 1.5L = 500 (1 bag); 5L = 0
 * Caps + top seals: high starting balances so every run can subtract capacity.
 */
class SyncProductionPackaging extends Command
{
    protected $signature = 'production:sync-packaging {--force : Re-apply BOM and stock targets}';

    protected $description = 'Sync production BOM ratios and packaging material opening stock';

    /** @var array<string,int> label pieces by size code */
    private const LABEL_STOCK = [
        '0.5L' => 984,   // 41 bales × 24 bottles
        '1L' => 10000,
        '1.5L' => 3000,
        '5L' => 2500,
    ];

    /** @var array<string,int> bailing sheets by size code */
    private const BAILING_STOCK = [
        '0.5L' => 2000,  // 4 bags × 500
        '1L' => 250,
        '1.5L' => 500,   // 1 bag
        '5L' => 0,
    ];

    private const CAP_START = 120000;
    private const SEAL_START = 120000;

    public function handle(): int
    {
        $warehouse = Warehouse::whereIn('code', ['MAIN', 'WH-MAIN'])->orderBy('code')->first()
            ?? Warehouse::orderBy('name')->first();
        if (! $warehouse) {
            $this->error('No warehouse found.');

            return 1;
        }

        $director = User::whereHas('role', fn ($q) => $q->where('access_tier', 'director'))->first()
            ?? User::orderBy('created_at')->first();
        $actorId = $director?->id;
        if ($actorId) {
            Auth::loginUsingId($actorId);
        }

        DB::transaction(function () use ($warehouse, $actorId) {
            $materials = $this->ensureMaterials($actorId);
            $this->syncBom($materials, $actorId);
            $this->seedStock($materials, $warehouse->id, $actorId);
        });

        $this->info('Production packaging BOM + opening stock synced.');
        $this->line('  Labels 0.5L = 984 (41 bales); 1L = 10,000; 1.5L = 3,000; 5L = 2,500');
        $this->line('  Bailing 0.5L = 2,000; 1L = 250; 1.5L = 500; 5L = 0');
        $this->line('  Caps: 0.5/1/1.5 → CAP-28MM-DARK pool; 5/10/20L size caps + seals high start');
        $this->line('  BOM: per bale → N×(bottle+label+cap+seal) + 1 bailing sheet (sizes that bale)');

        return 0;
    }

    /**
     * @return array<string,Material>
     */
    private function ensureMaterials(?string $actorId): array
    {
        $defs = [
            ['code' => 'BOTTLE-0.5L', 'name' => '0.5L PET Bottles', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BOTTLE-1L', 'name' => '1L PET Bottles', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BOTTLE-1.5L', 'name' => '1.5L PET Bottles', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BOTTLE-5L', 'name' => '5L PET Containers', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BOTTLE-10L', 'name' => '10L PET Containers', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BOTTLE-20L', 'name' => '20L PET Containers', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-0.5L', 'name' => '0.5L Bottle Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-1L', 'name' => '1L Bottle Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-1.5L', 'name' => '1.5L Bottle Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-5L', 'name' => '5L Container Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-10L', 'name' => '10L Container Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'LABEL-20L', 'name' => '20L Container Labels', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BAILING-0.5L', 'name' => '0.5L Bailing Paper', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BAILING-1L', 'name' => '1L Bailing Paper', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BAILING-1.5L', 'name' => '1.5L Bailing Paper', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BAILING-5L', 'name' => '5L Bailing Paper', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-0.5L', 'name' => '0.5L Bottle Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-1L', 'name' => '1L Bottle Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-1.5L', 'name' => '1.5L Bottle Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-5L', 'name' => '5L Container Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-10L', 'name' => '10L Container Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-20L', 'name' => '20L Container Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'CAP-28MM-DARK', 'name' => 'Dark Blue 28mm Caps', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'SEAL-BOTTLE', 'name' => 'Bottle Cap Seals', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'SEAL-REFILL', 'name' => 'Refill Jerrican Top Seals', 'category' => 'Packaging', 'uom' => 'PCS'],
        ];

        $out = [];
        foreach ($defs as $def) {
            $mat = Material::where('code', $def['code'])->first();
            if (! $mat) {
                $mat = Material::create([
                    'code' => $def['code'],
                    'name' => $def['name'],
                    'category' => $def['category'],
                    'uom' => $def['uom'],
                    'is_consumable' => true,
                    'min_level' => 0,
                    'unit_cost' => 0,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }
            $out[$def['code']] = $mat;
        }

        return $out;
    }

    /**
     * @param  array<string,Material>  $materials
     */
    private function syncBom(array $materials, ?string $actorId): void
    {
        $skus = Sku::where('active', true)->get();

        foreach ($skus as $sku) {
            $sizeKey = round((float) $sku->size_liters, 2);
            if (! in_array($sizeKey, [0.5, 1.0, 1.5, 5.0, 10.0, 20.0], true)) {
                continue;
            }

            $size = $sku->sizeCode(); // e.g. 0.5L
            $bpb = $sku->bottlesPerBale();
            $brand = strtolower((string) $sku->brand);

            $lines = [];
            $bottleCode = "BOTTLE-{$size}";
            $labelCode = "LABEL-{$size}";
            // 0.5 / 1 / 1.5 share the Intripet dark-blue 28mm cap pool; larger
            // sizes keep size-specific caps.
            $capCode = in_array($sizeKey, [0.5, 1.0, 1.5], true) ? 'CAP-28MM-DARK' : "CAP-{$size}";
            $bailingCode = "BAILING-{$size}";

            if (isset($materials[$bottleCode])) {
                $lines[$bottleCode] = $bpb;
            }
            if (isset($materials[$labelCode])) {
                $lines[$labelCode] = $bpb;
            }

            if (isset($materials[$capCode])) {
                $lines[$capCode] = $bpb;
            }

            $isRefill = str_contains($brand, 'refill') || str_starts_with($sku->code, 'REFILL');
            $sealCode = $isRefill ? 'SEAL-REFILL' : 'SEAL-BOTTLE';
            if (isset($materials[$sealCode])) {
                $lines[$sealCode] = $bpb;
            }

            if ($sku->usesBailingPaper() && isset($materials[$bailingCode])) {
                $lines[$bailingCode] = 1;
            }

            // Replace BOM for this SKU with the automated packaging set.
            BomItem::withTrashed()->where('sku_id', $sku->id)->forceDelete();

            foreach ($lines as $code => $qty) {
                BomItem::create([
                    'sku_id' => $sku->id,
                    'material_id' => $materials[$code]->id,
                    'qty_per_unit' => $qty,
                    'uom' => 'PCS',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }
        }
    }

    /**
     * @param  array<string,Material>  $materials
     */
    private function seedStock(array $materials, string $warehouseId, ?string $actorId): void
    {
        $inventory = new InventoryController();

        foreach (self::LABEL_STOCK as $size => $qty) {
            $this->setMaterialQty($inventory, $materials["LABEL-{$size}"], $warehouseId, $qty, "LABEL-{$size}-OPEN", $actorId);
        }

        foreach (self::BAILING_STOCK as $size => $qty) {
            $this->setMaterialQty($inventory, $materials["BAILING-{$size}"], $warehouseId, $qty, "BAILING-{$size}-OPEN", $actorId);
        }

        // Small bottles consume CAP-28MM-DARK — do not invent parallel size-cap piles.
        foreach (['CAP-0.5L', 'CAP-1L', 'CAP-1.5L'] as $code) {
            if (isset($materials[$code])) {
                $this->setMaterialQty($inventory, $materials[$code], $warehouseId, 0, "{$code}-CLEAR", $actorId);
            }
        }

        foreach (['CAP-5L', 'CAP-10L', 'CAP-20L'] as $code) {
            $this->setMaterialQty($inventory, $materials[$code], $warehouseId, self::CAP_START, "{$code}-OPEN", $actorId);
        }

        // Intripet 28mm pool: keep real receipt if present; otherwise open high for capacity tracking.
        if (isset($materials['CAP-28MM-DARK'])) {
            $current = $this->currentQty($materials['CAP-28MM-DARK']->id, $warehouseId);
            if ($current < 1) {
                $this->setMaterialQty($inventory, $materials['CAP-28MM-DARK'], $warehouseId, self::CAP_START, 'CAP-28MM-OPEN', $actorId);
            }
        }

        $this->setMaterialQty($inventory, $materials['SEAL-BOTTLE'], $warehouseId, self::SEAL_START, 'SEAL-BOTTLE-OPEN', $actorId);
        $this->setMaterialQty($inventory, $materials['SEAL-REFILL'], $warehouseId, self::SEAL_START, 'SEAL-REFILL-OPEN', $actorId);
    }

    private function currentQty(string $materialId, string $warehouseId): float
    {
        return (float) (StockItem::where('item_type', 'material')
            ->where('material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->value('qty') ?? 0);
    }

    private function setMaterialQty(
        InventoryController $inventory,
        Material $material,
        string $warehouseId,
        float $target,
        string $batchTag,
        ?string $actorId
    ): void {
        $current = $this->currentQty($material->id, $warehouseId);
        $delta = $target - $current;
        if (abs($delta) < 0.0001) {
            return;
        }

        if ($delta > 0) {
            $batch = MaterialBatch::create([
                'material_id' => $material->id,
                'batch_number' => $batchTag . '-' . now()->format('YmdHis'),
                'arrival_type' => 'opening_stock',
                'purchase_date' => now()->toDateString(),
                'supplier_name' => 'Opening stock / ops count',
                'supplier_code' => 'OPENING',
                'unit_cost' => 0,
                'transport_cost' => 0,
                'qty_received' => $delta,
                'bags_received' => $delta,
                'bottles_per_bag' => 1,
                'bottles_per_bale' => 1,
                'bales_expected' => $delta,
                'qty_remaining' => $delta,
                'package_unit' => 'pcs',
                'warehouse_id' => $warehouseId,
                'received_by' => $actorId,
                'notes' => 'Production packaging opening balance (sync)',
                'quality_status' => 'passed',
                'quality_checked_at' => now(),
                'quality_checked_by' => $actorId,
                'quality_notes' => 'Accepted opening count',
            ]);

            $inventory->recordMove([
                'move_type' => 'grn',
                'item_type' => 'material',
                'material_id' => $material->id,
                'warehouse_to_id' => $warehouseId,
                'qty' => $delta,
                'uom' => $material->uom ?: 'PCS',
                'unit_cost' => 0,
                'ref_entity' => 'material_batch',
                'ref_id' => $batch->id,
            ]);
        } else {
            $inventory->recordMove([
                'move_type' => 'adjust',
                'item_type' => 'material',
                'material_id' => $material->id,
                'warehouse_from_id' => $warehouseId,
                'qty' => abs($delta),
                'uom' => $material->uom ?: 'PCS',
                'ref_entity' => 'opening_stock_correct',
                'ref_id' => $material->id,
            ], allowNegative: true);
        }
    }
}
