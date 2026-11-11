<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refill_tallies', function (Blueprint $table) {
            if (! Schema::hasColumn('refill_tallies', 'unit_price')) {
                $table->decimal('unit_price', 12, 2)->nullable()->after('qty');
            }
            if (! Schema::hasColumn('refill_tallies', 'qty_basis')) {
                $table->string('qty_basis', 20)->default('jerrican')->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('refill_tallies', function (Blueprint $table) {
            if (Schema::hasColumn('refill_tallies', 'qty_basis')) {
                $table->dropColumn('qty_basis');
            }
            if (Schema::hasColumn('refill_tallies', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });
    }
};
