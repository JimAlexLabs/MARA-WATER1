<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 2 (dark mode). The spec asks for a per-user setting, not
 * a session default -- the generic `settings` key-value table is
 * deliberately global/system-wide (one row per key, shared by everyone;
 * see BackupService/Phase 12's notifications_enabled lesson), so a
 * genuinely per-user preference has to live on the user's own row,
 * the same way first_name/phone/etc. do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('light')->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
