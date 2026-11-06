<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refill_tallies')) {
            return;
        }

        Schema::create('refill_tallies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('tally_date');
            $table->decimal('size_liters', 8, 2);
            $table->unsignedInteger('qty');
            $table->string('notes', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('tally_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refill_tallies');
    }
};
