<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: replaces "HSL DEBTORS LEDGER" -- per customer: date, details,
 * cheque/invoice number, payment voucher number, debit, credit. Running
 * balance is derived at read time (same reasoning as petty_cash_entries),
 * not stored.
 *
 * Auto-populated from credit sales (OrderController::logSale(), Phase 7)
 * as a debit against the invoice/debt it created; manual entries (debit
 * or credit) stay possible for adjustments and opening balances. A
 * payment against a specific debt posts a credit here AND reduces
 * debts.balance in the same transaction (DebtorLedgerController::
 * recordPayment) -- debt_id is nullable because manual/opening-balance
 * entries don't necessarily reference one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debtor_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('debt_id')->nullable();
            $table->date('entry_date');
            $table->string('details', 500);
            $table->string('reference_no', 100)->nullable();
            $table->string('voucher_no', 100)->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('debt_id')->references('id')->on('debts')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['customer_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debtor_ledger_entries');
    }
};
