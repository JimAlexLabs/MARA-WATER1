<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('user_sessions', 'dashboard_tier')) {
                $table->string('dashboard_tier', 32)->nullable()->after('device_info');
            }
            if (! Schema::hasColumn('user_sessions', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('user_sessions', 'dashboard_tier')) {
                $table->dropColumn('dashboard_tier');
            }
            if (Schema::hasColumn('user_sessions', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
