<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Director-visible plaintext password reminder so admins can look up
 * staff login credentials without a reset round-trip. Never returned to
 * non-directors (UserController makeHidden).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_password_note')->nullable()->after('password_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_password_note');
        });
    }
};
