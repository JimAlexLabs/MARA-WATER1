<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 4 (HR & Payroll), Staff record fields from the "Staff
 * Database" sheet not already covered by existing columns. Already have:
 * id_number (National ID), employment_date (Date of Employment), salary
 * (used as Basic Salary -- see User model docblock), status.
 *
 * Gross Salary is deliberately NOT a column here -- the spec says
 * "calculated not typed" (basic + house allowance), so it's a model
 * accessor instead, the same "derive, don't store" pattern used for
 * running balances elsewhere in this app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_number', 20)->nullable()->unique()->after('id_number');
            $table->text('address')->nullable()->after('phone');
            $table->string('kra_pin', 20)->nullable()->after('id_number');
            $table->string('nssf_number', 20)->nullable()->after('kra_pin');
            $table->string('shif_number', 20)->nullable()->after('nssf_number');
            $table->date('date_of_birth')->nullable()->after('employment_date');
            $table->string('terms_of_employment', 50)->nullable()->after('date_of_birth');
            $table->decimal('house_allowance', 12, 2)->default(0)->after('salary');
            $table->string('bank_name', 100)->nullable()->after('house_allowance');
            $table->string('bank_branch', 100)->nullable()->after('bank_name');
            $table->string('bank_account_number', 50)->nullable()->after('bank_branch');
            $table->string('bank_code', 20)->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'staff_number', 'address', 'kra_pin', 'nssf_number', 'shif_number',
                'date_of_birth', 'terms_of_employment', 'house_allowance',
                'bank_name', 'bank_branch', 'bank_account_number', 'bank_code',
            ]);
        });
    }
};
