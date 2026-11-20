<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'sale_channel')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('sale_channel', 20)->nullable()->after('payment_method');
            });
        }

        $exists = DB::table('chart_of_accounts')->where('code', '5460')->exists();
        if (! $exists) {
            DB::table('chart_of_accounts')->insert([
                'id' => (string) Str::uuid(),
                'code' => '5460',
                'description' => 'Tokens',
                'category' => 'expense',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'sale_channel')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('sale_channel');
            });
        }
    }
};
