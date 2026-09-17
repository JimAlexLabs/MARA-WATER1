<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 4: "Advances & Loans" -- linked to staff so outstanding
 * balances auto-populate into the next payroll run's deductions. One row
 * per loan/advance; `balance` is the running amount still owed and is
 * decremented by PayrollService each time a run deducts against it,
 * exactly the way a real loan ledger works (not derived-on-read, since
 * each deduction is itself a discrete historical event this table is
 * the record of).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->enum('type', ['advance', 'loan', 'sacco_loan', 'sacco_advance'])->default('loan');
            $table->decimal('principal', 12, 2);
            $table->decimal('monthly_deduction', 12, 2);
            $table->decimal('balance', 12, 2); // starts == principal, decremented per payroll run
            $table->date('issued_date');
            $table->string('status', 20)->default('active'); // active | settled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loans');
    }
};
