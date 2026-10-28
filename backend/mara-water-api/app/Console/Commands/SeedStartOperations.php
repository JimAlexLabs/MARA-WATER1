<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InventoryController;
use App\Models\ChartOfAccount;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\PettyCashEntry;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load Rongo start-of-operations stock and payables after a Danger Zone wipe.
 *
 * Intripet SO-000930 (10 Oct 2026) + transport KES 45,000 +
 * Shadey Plumbing SPC-2026-1042 (paid).
 */
class SeedStartOperations extends Command
{
    protected $signature = 'operations:seed-start {--force : Run even if stock already exists}';

    protected $description = 'Seed starting bottles/caps inventory and supplier payables for Rongo start';

    public function handle(): int
    {
        $warehouse = Warehouse::where('code', 'MAIN')->first() ?? Warehouse::orderBy('code')->first();
        if (!$warehouse) {
            $this->error('No warehouse found.');
            return 1;
        }

        $director = User::whereHas('role', fn ($q) => $q->where('access_tier', 'director'))->first()
            ?? User::orderBy('created_at')->first();
        $actorId = $director?->id;

        $existing = MaterialBatch::where('batch_number', 'like', 'SO-000930%')->count();
        if ($existing > 0 && !$this->option('force')) {
            $this->warn('Start stock already present (SO-000930). Pass --force to re-post.');
            return 0;
        }

        DB::transaction(function () use ($warehouse, $actorId) {
            $lines = [
                [
                    'code' => 'BOTTLE-0.5L',
                    'name' => '0.5L PET Bottles',
                    'sku_code' => 'WATER-0.5L',
                    'qty' => 23800,
                    'unit_cost' => 5.20,
                    'product' => '500ML SPIRAL BLUE',
                ],
                [
                    'code' => 'BOTTLE-1L',
                    'name' => '1L PET Bottles',
                    'sku_code' => 'WATER-1L',
                    'qty' => 2640,
                    'unit_cost' => 8.70,
                    'product' => '1 LTR SPIRAL BLUE',
                ],
                [
                    'code' => 'BOTTLE-1.5L',
                    'name' => '1.5L PET Bottles',
                    'sku_code' => 'WATER-1.5L',
                    'qty' => 5712,
                    'unit_cost' => 12.20,
                    'product' => '1.5 LTR SPIRAL BLUE',
                ],
                [
                    'code' => 'CAP-28MM-DARK',
                    'name' => 'Dark Blue 28mm Caps',
                    'sku_code' => null,
                    'qty' => 32152,
                    'unit_cost' => 0.80,
                    'product' => 'DARK BLUE 28MM CAPS',
                    'create_if_missing' => true,
                    'category' => 'Packaging',
                    'uom' => 'PCS',
                ],
            ];

            $inventory = new InventoryController();

            foreach ($lines as $i => $line) {
                $material = Material::where('code', $line['code'])->first();
                if (!$material && !empty($line['create_if_missing'])) {
                    $material = Material::create([
                        'code' => $line['code'],
                        'name' => $line['name'],
                        'category' => $line['category'] ?? 'Packaging',
                        'uom' => $line['uom'] ?? 'PCS',
                        'unit_cost' => $line['unit_cost'],
                        'is_consumable' => true,
                        'min_level' => 1000,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }
                if (!$material) {
                    throw new \RuntimeException("Missing material {$line['code']}");
                }

                $skuId = null;
                if (!empty($line['sku_code'])) {
                    $skuId = DB::table('skus')->where('code', $line['sku_code'])->whereNull('deleted_at')->value('id');
                }

                $batch = MaterialBatch::create([
                    'material_id' => $material->id,
                    'sku_id' => $skuId,
                    'batch_number' => 'SO-000930-' . ($i + 1),
                    'arrival_type' => 'goods_received',
                    'purchase_date' => '2026-10-10',
                    'supplier_name' => 'Intripet Industries Limited',
                    'supplier_code' => 'OTHER',
                    'unit_cost' => $line['unit_cost'],
                    'transport_cost' => $i === 0 ? 45000 : 0,
                    'qty_received' => $line['qty'],
                    'bags_received' => $line['qty'],
                    'bottles_per_bag' => 1,
                    'bottles_per_bale' => 24,
                    'bales_expected' => round($line['qty'] / 24, 3),
                    'qty_remaining' => $line['qty'],
                    'package_unit' => 'pcs',
                    'warehouse_id' => $warehouse->id,
                    'received_by' => $actorId,
                    'notes' => "Intripet SO-000930 · {$line['product']} · HOMA SPRINGS / Mara Water Rongo start stock. Created by Violet Mombo.",
                    'document_path' => 'SO-000930',
                    'quality_status' => 'passed',
                    'quality_checked_at' => now(),
                    'quality_checked_by' => $actorId,
                    'quality_notes' => 'Starting inventory for production — accepted on receipt.',
                ]);

                $inventory->recordMove([
                    'move_type' => 'grn',
                    'item_type' => 'material',
                    'material_id' => $material->id,
                    'warehouse_to_id' => $warehouse->id,
                    'qty' => $line['qty'],
                    'uom' => $material->uom ?: 'PCS',
                    'unit_cost' => $line['unit_cost'],
                    'ref_entity' => 'material_batch',
                    'ref_id' => $batch->id,
                ]);

                $material->update(['unit_cost' => $line['unit_cost'], 'updated_by' => $actorId]);
            }

            // Payables: Intripet bottles (open), transport (open), Shadey plumbing (paid).
            SupplierLedgerEntry::create([
                'supplier_name' => 'Intripet Industries Limited',
                'supplier_code' => 'INTRIPET',
                'entry_date' => '2026-10-10',
                'details' => 'Sales Order SO-000930 — bottles 500ml/1L/1.5L spiral blue + dark blue 28mm caps (incl. VAT)',
                'reference_no' => 'SO-000930',
                'category' => 'materials',
                'status' => 'open',
                'debit' => 0,
                'credit' => 242136.00,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            SupplierLedgerEntry::create([
                'supplier_name' => 'Bottle delivery transport',
                'supplier_code' => 'TRANSPORT',
                'entry_date' => '2026-10-10',
                'details' => 'Transport cost for Intripet SO-000930 delivery to Rongo',
                'reference_no' => 'SO-000930-TRANSPORT',
                'category' => 'transport',
                'status' => 'open',
                'debit' => 0,
                'credit' => 45000.00,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            SupplierLedgerEntry::create([
                'supplier_name' => 'Shadey Plumbing Company',
                'supplier_code' => 'SHADEY',
                'entry_date' => '2026-10-08',
                'details' => 'Invoice SPC-2026-1042 — plumbing, filters, labor & transport logistics (Rongo plant service/maintenance)',
                'reference_no' => 'SPC-2026-1042',
                'category' => 'maintenance',
                'status' => 'open',
                'debit' => 0,
                'credit' => 33500.00,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            SupplierLedgerEntry::create([
                'supplier_name' => 'Shadey Plumbing Company',
                'supplier_code' => 'SHADEY',
                'entry_date' => '2026-10-08',
                'details' => 'Payment for SPC-2026-1042 (materials + labor milestones settled)',
                'reference_no' => 'SPC-2026-1042',
                'voucher_no' => 'PAY-SHADEY-001',
                'category' => 'maintenance',
                'status' => 'paid',
                'debit' => 33500.00,
                'credit' => 0,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $repairs = ChartOfAccount::where('code', '5430')->first();
            if ($repairs) {
                PettyCashEntry::create([
                    'entry_date' => '2026-10-08',
                    'account_id' => $repairs->id,
                    'description' => 'Shadey Plumbing SPC-2026-1042 — Rongo service & maintenance (paid)',
                    'requestor_name' => 'Operations',
                    'amount_in' => 0,
                    'amount_out' => 33500.00,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }
        });

        $this->info('Starting inventory and payables seeded for Rongo operations.');
        $this->line('  Bottles: 23,800 × 0.5L + 2,640 × 1L + 5,712 × 1.5L');
        $this->line('  Caps: 32,152 dark blue 28mm');
        $this->line('  Payables open: Intripet 242,136 + Transport 45,000');
        $this->line('  Paid: Shadey Plumbing 33,500');

        return 0;
    }
}
