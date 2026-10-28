<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier / creditors ledger — mirror of debtor_ledger_entries.
 * credit = bill we owe (payable up), debit = payment we made (payable down).
 * Running balance is derived at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('supplier_name', 200);
            $table->string('supplier_code', 50)->nullable();
            $table->date('entry_date');
            $table->string('details', 500);
            $table->string('reference_no', 100)->nullable();
            $table->string('voucher_no', 100)->nullable();
            $table->string('category', 50)->nullable(); // materials|transport|maintenance|other
            $table->string('status', 20)->default('open'); // open|paid|partial
            $table->decimal('debit', 14, 2)->default(0);  // payment made
            $table->decimal('credit', 14, 2)->default(0); // amount billed / owed
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['supplier_name', 'entry_date']);
            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
