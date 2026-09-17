<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (Final verification pass). The notifications table (bell icon,
 * top nav, every page) has no severity/category column, but the frontend
 * already picks an icon color based on one ('alert'/'info'/'success').
 * Turns out the entire notification read path (NotificationController)
 * was pre-upgrade placeholder scaffolding -- always returned an empty,
 * hardcoded list regardless of what was in this table, and `send()`
 * never actually inserted a row. Fixing that (this phase) needs a real
 * column to carry the type the frontend already expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'type')) {
                $table->string('type', 20)->default('info')->after('channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
