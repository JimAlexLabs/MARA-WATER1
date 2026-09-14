<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (HR bulk employee import). `users` doubles as the employee
 * record -- it already carries first/last name, phone, role, department,
 * status. What it's missing for a real HR roster: national ID number,
 * employment date, salary.
 *
 * It's also currently impossible to add a staff member who isn't also a
 * system login: email and password_hash are both NOT NULL. Most of the
 * roster this phase needs to bulk-add (drivers, storekeepers, packaging
 * staff) don't need app access. Making both nullable means "no
 * password_hash" naturally means "can't log in" (Hash::check() against a
 * null hash just fails) rather than needing a fake placeholder password
 * or a separate employees table duplicating everything users already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'id_number')) {
                $table->string('id_number', 20)->nullable()->unique()->after('phone');
            }
            if (!Schema::hasColumn('users', 'employment_date')) {
                $table->date('employment_date')->nullable()->after('status');
            }
            if (!Schema::hasColumn('users', 'salary')) {
                $table->decimal('salary', 12, 2)->nullable()->after('employment_date');
            }
        });

        DB::statement('ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL');
        DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['id_number', 'employment_date', 'salary']);
        });
    }
};
