<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 Phase 10: recording a raw-material/packaging purchase
 * (bottles, caps, labels, chemicals, ...) previously only let you add
 * the quantity to one running-total StockItem row for that material --
 * no batch number, supplier, unit cost, or purchase date was ever kept
 * per purchase, so two different deliveries of the same material were
 * indistinguishable after the fact.
 *
 * This table is purely the traceability record of each purchase; it
 * does NOT change how stock is deducted or rolled up. Receiving a batch
 * still posts a normal 'grn' StockMove through the existing
 * InventoryController::recordMove() -> updateStockLevels() path, which
 * still updates the one running-total StockItem row per
 * material+warehouse exactly as before -- so every low-stock check, BOM
 * consumption balance, and materials list total keeps working unchanged.
 * What's new is only that the purchase itself is now retrievable later
 * by batch number/date/supplier for QA traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('material_id');
            $table->string('batch_number', 50);
            $table->date('purchase_date');
            // Plain text, not a supplier_id FK -- there's no Supplier
            // CRUD/API anywhere in the app yet (the `suppliers` table and
            // Material's supplier_id exist, but nothing ever populates
            // or lists them), so a free-text name is what's actually
            // usable today without pulling in a whole new module just
            // for this field.
            $table->string('supplier_name', 200)->nullable();
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('qty_received', 12, 3);
            $table->uuid('warehouse_id');
            $table->uuid('received_by');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('material_id')->references('id')->on('materials');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('received_by')->references('id')->on('users');
            $table->index(['material_id', 'purchase_date']);
            $table->unique(['material_id', 'batch_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_batches');
    }
};
