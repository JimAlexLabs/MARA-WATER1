<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 Phase 5: in-app issue reporting. A driver/salesperson raises an
 * issue (subject + message + optional photo); Manager/Director see it in
 * an inbox and reply -- a threaded message list per issue, not real-time
 * chat. `issues` carries the subject/status/who-raised-it; every message
 * in the thread (including the original one) lives in `issue_messages` so
 * rendering a thread is always just "read this issue's messages in
 * order", with no special-casing of the first one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject', 200);
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['status', 'created_at']);
            $table->index('created_by');
        });

        Schema::create('issue_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('issue_id');
            $table->uuid('sender_id');
            $table->text('body');
            $table->string('photo_path')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users');
            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_messages');
        Schema::dropIfExists('issues');
    }
};
