<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('month'); // always the 1st of the month it covers
            $table->string('status', 20)->default('draft'); // draft | finalized
            $table->uuid('run_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->unique('month'); // one run per calendar month
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
