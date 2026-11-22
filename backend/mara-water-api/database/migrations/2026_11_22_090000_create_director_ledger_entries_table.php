<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Director accounts ledger. Fuel, tokens, petty cash and the statement
 * stay in their own tables and are read live. This table is only the
 * lines an admin adds: an expense, a withdrawal from the utility account,
 * money borrowed outside, or an amount still to pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('director_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('entry_date');
            $table->string('kind', 30);
            $table->string('title', 200);
            $table->string('explanation', 1000);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->index('entry_date');
        });

        $now = now();
        DB::table('director_ledger_entries')->insert([
            [
                'id' => (string) Str::uuid(),
                'entry_date' => '2026-11-22',
                'kind' => 'expense',
                'title' => 'Salesperson salary advance',
                'explanation' => 'Paid 5,000 in advance. His pay for the 10 days (two weeks) of operation is 15,000. His wife had a baby and he asked for the advance. 10,000 of that pay is still to be paid.',
                'amount' => 5000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'entry_date' => '2026-11-22',
                'kind' => 'payable',
                'title' => 'Salesperson salary still to pay',
                'explanation' => 'Balance of the 15,000 pay for 10 days, after the 5,000 advance. This is money still to pay out. It is not Diana or Derrick capital, and it is not a customer debt.',
                'amount' => 10000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'entry_date' => '2026-11-29',
                'kind' => 'expense',
                'title' => 'Driver trip to Sori, Luanda and Riat',
                'explanation' => '1,000 paid to the driver to go to Sori, Luanda and Riat and talk to the previous distributors.',
                'amount' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('director_ledger_entries');
    }
};
