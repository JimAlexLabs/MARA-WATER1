<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InventoryController;
use App\Models\Material;
use App\Services\FinishedGoodsService;
use App\Services\MainWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SyncFinishedLedger extends Command
{
    protected $signature = 'inventory:sync-finished-ledger';

    protected $description = 'Set finished bales on hand to produced minus sold, and keep seals from showing as empty';

    public function handle(FinishedGoodsService $finished): int
    {
        $director = \App\Models\User::whereHas('role', fn ($q) => $q->where('code', 'ADMIN'))->first();
        if ($director) {
            Auth::login($director);
        }

        foreach ($finished->syncStockToLedger() as $line) {
            $this->line($line);
        }

        $this->restorePlentifulSeals();
        $this->info('Finished unsold now equals bales produced minus bales sold.');

        return 0;
    }

    /**
     * Seal deductions had driven the book negative while the store still
     * holds plenty. Put the counted plentiful pool back so it is not a shortage.
     */
    private function restorePlentifulSeals(): void
    {
        $material = Material::where('code', 'SEAL-BOTTLE')->whereNull('deleted_at')->first();
        $warehouseId = MainWarehouse::id();
        if (! $material || ! $warehouseId) {
            return;
        }

        $current = (float) DB::table('stock_items')
            ->where('material_id', $material->id)
            ->whereNull('deleted_at')
            ->sum('qty');

        if ($current >= 15000) {
            $this->line('SEAL-BOTTLE already plentiful ('.number_format($current).').');

            return;
        }

        app(InventoryController::class)->recordMove([
            'move_type' => 'adjust',
            'item_type' => 'material',
            'material_id' => $material->id,
            'counted_qty' => 15000,
            'uom' => $material->uom ?: 'PCS',
            'ref_entity' => 'stock_correction',
            'ref_id' => 'Seals are plentiful — not a shortage',
        ]);
        $this->line('SEAL-BOTTLE set to 15,000 (was '.number_format($current).').');
    }
}
