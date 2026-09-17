<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 4: "Build salary/role templates... so onboarding a new
 * hire means picking a role and adjusting the specific numbers, not
 * building their pay structure from scratch." Deliberately not seeded
 * with real amounts here (see PayrollSeeder note) -- picking numbers for
 * a real business's actual role pay scales is exactly the kind of
 * consequential business data this project's ground rules say to ask
 * about, not invent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100); // e.g. "Driver", "Production", "Management"
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('house_allowance', 12, 2)->default(0);
            $table->decimal('telephone_allowance', 12, 2)->default(0);
            $table->decimal('other_allowance', 12, 2)->default(0);
            $table->string('terms_of_employment', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_templates');
    }
};
