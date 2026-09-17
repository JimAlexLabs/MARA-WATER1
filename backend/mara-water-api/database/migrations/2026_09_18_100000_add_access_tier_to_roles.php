<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 11 (Roles & permissions). The app already has a granular
 * 10-role catalog (ADMIN/Director, QA, RIC, BP, SMM, SO, DRV, FO, STK,
 * AUD) that maps to real job titles at the business -- worth keeping for
 * HR/organizational purposes -- but the spec's access-control model is
 * exactly four tiers: driver, manager, investor, director. access_tier
 * is that mapping: every existing job-title role collapses onto one of
 * the four tiers for authorization purposes (real backend enforcement,
 * not client-side hiding), without renaming or deleting any of them.
 *
 * - ADMIN (Director) -> director
 * - DRV (Driver) -> driver
 * - everything else (QA, RIC, BP, SMM, SO, FO, STK, AUD) -> manager --
 *   this is exactly the spec's "Manager / Accountant / Financier: full
 *   day-to-day operational access" bucket; none of these 8 job titles
 *   currently have a real user assigned (verified live before writing
 *   this), so collapsing them onto one access tier changes no one's
 *   actual access today.
 * - a new INVESTOR role is added (none existed) since the spec's
 *   fourth tier -- a deliberately limited, read-only summary view --
 *   has no corresponding job title in the existing catalog at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->enum('access_tier', ['driver', 'manager', 'investor', 'director'])->nullable()->after('code');
        });

        DB::table('roles')->where('code', 'ADMIN')->update(['access_tier' => 'director']);
        DB::table('roles')->where('code', 'DRV')->update(['access_tier' => 'driver']);
        DB::table('roles')->whereNotIn('code', ['ADMIN', 'DRV'])->update(['access_tier' => 'manager']);

        if (!DB::table('roles')->where('code', 'INVESTOR')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) \Illuminate\Support\Str::orderedUuid(),
                'code' => 'INVESTOR',
                'access_tier' => 'investor',
                'name' => 'Investor',
                'description' => 'Read-only daily performance summary -- no line-level financial, salary, or debtor detail.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Left nullable rather than converting to NOT NULL (that needs
        // doctrine/dbal for a MySQL column-type change, not installed
        // here) -- every row is backfilled above, and application code
        // treats a null access_tier as "no access" rather than assuming
        // it can't happen.
    }

    public function down(): void
    {
        DB::table('roles')->where('code', 'INVESTOR')->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('access_tier');
        });
    }
};
