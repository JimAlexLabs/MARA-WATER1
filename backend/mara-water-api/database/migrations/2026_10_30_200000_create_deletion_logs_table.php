<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deletion_logs')) {
            return;
        }

        Schema::create('deletion_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('entity', 120)->index();
            $table->string('entity_id', 64)->nullable()->index();
            $table->string('action', 32)->default('delete')->index(); // soft_delete | force_delete | delete
            $table->json('snapshot')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_logs');
    }
};
