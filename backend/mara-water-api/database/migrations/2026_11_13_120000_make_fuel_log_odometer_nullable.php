<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fuel_logs MODIFY odometer INT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE fuel_logs SET odometer = 0 WHERE odometer IS NULL');
        DB::statement('ALTER TABLE fuel_logs MODIFY odometer INT NOT NULL');
    }
};
