<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InventoryController;
use App\Models\Batch;
use App\Models\Material;
use App\Models\PackagingRun;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use App\Services\FinishedGoodsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 24 Nov 2026 physical count: morning finished stock before trips was
 * 540 / 161 / 59; after today's two trips (1+1+1 then 28+3+12) on hand is
 * 511 / 157 / 46. 1.5L production is 475 not 478. Bailing paper and empty
 * 1L / 1.5L bottles match the store count. Fuel range uses 9 km/L.
 */
class ApplyNov24PhysicalStock extends Command
{
    protected $signature = 'inventory:apply-nov24-physical';

    protected $description = 'Align finished bales, bailing paper, empty bottles and fuel km/L to the 24 Nov 2026 physical count';

    public function handle(FinishedGoodsService $finished, InventoryController $inventory): int
    {
        $director = User::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
        if ($director) {
            Auth::login($director);
        }

        $this->fixFifteenProduction();
        $this->setFinished([
            'PREM-0.5L' => 511,
            'PREM-1L' => 157,
            'PREM-1.5L' => 46,
        ], $inventory);
        $this->setMaterials([
            'BAILING-0.5L' => 854,
            'BAILING-1L' => 306,
            'BAILING-1.5L' => 33,
            'BAILING-5L' => 316,
            'BOTTLE-1L' => 0,
            'BOTTLE-1.5L' => 0,
        ], $inventory);

        Setting::putMany(['fuel_km_per_litre' => 9]);
        $this->info('Fuel range set to 9 km per litre.');

        foreach (['PREM-0.5L', 'PREM-1L', 'PREM-1.5L'] as $code) {
            $id = Sku::where('code', $code)->value('id');
            if (! $id) {
                continue;
            }
            $row = collect($finished->balances())->firstWhere('sku_id', $id);
            $this->line(sprintf(
                '%s produced %s, sold %s, on hand %s',
                $code,
                $row['produced_bales'] ?? '?',
                $row['sold_bales'] ?? '?',
                $row['unsold_bales'] ?? '?'
            ));
        }

        return self::SUCCESS;
    }

    private function fixFifteenProduction(): void
    {
        $batch = Batch::query()
            ->where('code', 'BATCH-PREM-1.5L-20261117-001')
            ->whereNull('deleted_at')
            ->first();
        if ($batch && (float) $batch->actual_qty > 0) {
            $batch->update([
                'actual_qty' => 0,
                'planned_qty' => 0,
                'updated_by' => Auth::id(),
            ]);
            $this->info('1.5L batch 17 Nov set to 0 (478 → 475 produced).');
        }

        $runs = PackagingRun::query()
            ->whereNull('deleted_at')
            ->whereHas('sku', fn ($q) => $q->where('code', 'PREM-1.5L'))
            ->whereDate('run_end', '2026-11-17')
            ->where('good_qty', '>', 0)
            ->get();
        foreach ($runs as $run) {
            $run->update(['good_qty' => 0, 'updated_by' => Auth::id()]);
            $this->info('1.5L packaging run on 17 Nov set to 0 good bales.');
        }
    }

    /** @param  array<string,float|int>  $targets */
    private function setFinished(array $targets, InventoryController $inventory): void
    {
        foreach ($targets as $code => $qty) {
            $sku = Sku::where('code', $code)->whereNull('deleted_at')->first();
            if (! $sku) {
                $this->warn("SKU {$code} missing");
                continue;
            }
            $inventory->recordMove([
                'move_type' => 'adjust',
                'item_type' => 'sku',
                'sku_id' => $sku->id,
                'counted_qty' => $qty,
                'uom' => 'BALE',
                'ref_entity' => 'stock_correction',
                'ref_id' => 'Physical count 24 Nov 2026 after both trips',
            ], true);
            $this->line("{$code} finished stock → {$qty}");
        }
    }

    /** @param  array<string,float|int>  $targets */
    private function setMaterials(array $targets, InventoryController $inventory): void
    {
        foreach ($targets as $code => $qty) {
            $material = Material::where('code', $code)->whereNull('deleted_at')->first();
            if (! $material) {
                $this->warn("Material {$code} missing");
                continue;
            }
            $inventory->recordMove([
                'move_type' => 'adjust',
                'item_type' => 'material',
                'material_id' => $material->id,
                'counted_qty' => $qty,
                'uom' => $material->uom ?: 'PCS',
                'ref_entity' => 'stock_correction',
                'ref_id' => 'Physical warehouse count 24 Nov 2026',
            ], true);
            $this->line("{$code} → {$qty}");
        }
    }
}
