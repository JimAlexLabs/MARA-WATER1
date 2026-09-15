<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 (Customer base). customers already had name/type/phone/email/
 * address/route_id/price_tier -- solid groundwork. Missing: a contact
 * person distinct from the shop/org name, preferred products, typical
 * order size, payment terms, notes, and an active/inactive status.
 *
 * type was a real MySQL ENUM('retail','wholesale','corporate'). Widened
 * (not replaced) with 'hotel_restaurant' to reach the spec's four
 * categories -- retail/wholesale/corporate keep their existing seeded
 * rows valid; the app relabels wholesale -> "Distributor/Reseller" and
 * corporate -> "Institution" in the UI without touching stored values.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customers MODIFY type ENUM('retail','wholesale','corporate','hotel_restaurant') NOT NULL DEFAULT 'retail'");

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'contact_person')) {
                $table->string('contact_person', 150)->nullable()->after('name');
            }
            if (!Schema::hasColumn('customers', 'preferred_products')) {
                $table->string('preferred_products', 255)->nullable()->after('price_tier');
            }
            if (!Schema::hasColumn('customers', 'typical_order_size')) {
                $table->string('typical_order_size', 100)->nullable()->after('preferred_products');
            }
            if (!Schema::hasColumn('customers', 'payment_terms')) {
                $table->string('payment_terms', 20)->nullable()->after('typical_order_size');
            }
            if (!Schema::hasColumn('customers', 'notes')) {
                $table->text('notes')->nullable()->after('payment_terms');
            }
            if (!Schema::hasColumn('customers', 'status')) {
                $table->string('status', 20)->default('active')->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'preferred_products', 'typical_order_size', 'payment_terms', 'notes', 'status']);
        });
        DB::statement("ALTER TABLE customers MODIFY type ENUM('retail','wholesale','corporate') NOT NULL DEFAULT 'retail'");
    }
};
