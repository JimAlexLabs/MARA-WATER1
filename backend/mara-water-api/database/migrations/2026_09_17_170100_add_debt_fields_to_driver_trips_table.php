<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 6: "specifically to Log Driver Trip, since drivers are
 * the ones extending credit in the field." A trip's cash/mpesa fields
 * are trip-level totals (not itemized per customer -- that itemization
 * is Phase 7's job when it rebuilds this form), so debt is modelled the
 * same way here: one optional debt entry per trip. debt_id links to the
 * real Debt/DebtorLedgerEntry created when debt_amount > 0, so this
 * trip and the debtors ledger both point at the same record rather than
 * duplicating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->char('debt_customer_id', 36)->nullable()->after('mpesa_reference');
            $table->string('debt_signatory', 150)->nullable()->after('debt_customer_id');
            $table->decimal('debt_amount', 12, 2)->default(0)->after('debt_signatory');
            $table->date('debt_expected_repayment_date')->nullable()->after('debt_amount');
            $table->char('debt_id', 36)->nullable()->after('debt_expected_repayment_date');

            $table->foreign('debt_customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('debt_id')->references('id')->on('debts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropForeign(['debt_customer_id']);
            $table->dropForeign(['debt_id']);
            $table->dropColumn(['debt_customer_id', 'debt_signatory', 'debt_amount', 'debt_expected_repayment_date', 'debt_id']);
        });
    }
};
