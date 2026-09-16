<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: replaces "PETTY CASH SUMMARY G" -- date, M-Pesa reference,
 * account code, transaction description, requestor, amount in/out.
 * Running balance is deliberately NOT a stored column -- it's derived
 * (cumulative amount_in - amount_out ordered by date) at read time in
 * PettyCashController, so editing/deleting an entry can never leave a
 * stale balance sitting in the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('entry_date');
            $table->string('mpesa_reference', 50)->nullable();
            $table->uuid('account_id');
            $table->string('description', 500);
            $table->uuid('requestor_id')->nullable();
            $table->string('requestor_name', 150)->nullable(); // for a requestor with no user account
            $table->decimal('amount_in', 14, 2)->default(0);
            $table->decimal('amount_out', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('account_id')->references('id')->on('chart_of_accounts');
            $table->foreign('requestor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_entries');
    }
};
