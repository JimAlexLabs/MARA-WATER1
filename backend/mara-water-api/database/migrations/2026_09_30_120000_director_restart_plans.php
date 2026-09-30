<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable Director restart / what-if plan (Premium-only ops model).
 * One active plan row; assumptions JSON is the source of truth for inputs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('director_restart_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->default('Premium Restart Plan');
            $table->boolean('is_active')->default(true)->index();
            $table->json('assumptions');
            $table->text('notes')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('director_restart_plans');
    }
};
