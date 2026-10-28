<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE discrepancies MODIFY category ENUM('cash','stock','mileage','fuel') NOT NULL");
    }

    public function down(): void
    {
        DB::table('discrepancies')->where('category', 'fuel')->update(['category' => 'mileage']);
        DB::statement("ALTER TABLE discrepancies MODIFY category ENUM('cash','stock','mileage') NOT NULL");
    }
};
