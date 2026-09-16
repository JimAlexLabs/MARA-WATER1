<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 10: the "packaging materials stock watch" and "PPE tracking"/
 * "stationery stock" sections are just filtered views of the existing
 * materials/stock_items system (Phase 8) -- no need for a parallel
 * table. But seals, stickers, bailing paper (explicitly named in the
 * spec -- refill jerrican top seals hitting zero was flagged as a
 * direct blocker to sales), PPE, and stationery didn't exist as
 * materials at all yet, only bottles/caps/cartons/labels did.
 *
 * Deliberately NOT seeding min_level (reorder threshold) here, unlike
 * Phase 8/9's starter defaults -- how many gunboots or bailing paper
 * rolls this business actually needs is a real operational fact only
 * the business knows, not something inferable from the spec text.
 * Leaving it at the column's 0 default means these correctly show as
 * "no threshold set" rather than a guessed number, until someone
 * enters the real minimum via the Materials UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // Packaging -- seals explicitly split bottle vs refill per spec
            ['code' => 'SEAL-BOTTLE', 'name' => 'Bottle Cap Seals', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'SEAL-REFILL', 'name' => 'Refill Jerrican Top Seals', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'STICKER', 'name' => 'Product Stickers', 'category' => 'Packaging', 'uom' => 'PCS'],
            ['code' => 'BAILING-PAPER', 'name' => 'Bailing Paper', 'category' => 'Packaging', 'uom' => 'PCS'],
            // PPE
            ['code' => 'PPE-GUNBOOT', 'name' => 'Gunboots', 'category' => 'PPE', 'uom' => 'PAIRS'],
            ['code' => 'PPE-RAINCOAT', 'name' => 'Raincoats', 'category' => 'PPE', 'uom' => 'PCS'],
            ['code' => 'PPE-HAIRCOVER', 'name' => 'Hair Coverings', 'category' => 'PPE', 'uom' => 'PCS'],
            // Stationery
            ['code' => 'STAT-RECEIPT', 'name' => 'Receipt Books', 'category' => 'Stationery', 'uom' => 'BOOKS'],
            ['code' => 'STAT-DELIVERY', 'name' => 'Delivery Books', 'category' => 'Stationery', 'uom' => 'BOOKS'],
            ['code' => 'STAT-INVOICE', 'name' => 'Invoice Books', 'category' => 'Stationery', 'uom' => 'BOOKS'],
        ];

        foreach ($rows as &$row) {
            $row['id'] = (string) Str::uuid();
            $row['is_consumable'] = 1;
            $row['min_level'] = 0;
            $row['lead_time_days'] = 0;
            $row['unit_cost'] = 0;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('materials')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('materials')->whereIn('code', [
            'SEAL-BOTTLE', 'SEAL-REFILL', 'STICKER', 'BAILING-PAPER',
            'PPE-GUNBOOT', 'PPE-RAINCOAT', 'PPE-HAIRCOVER',
            'STAT-RECEIPT', 'STAT-DELIVERY', 'STAT-INVOICE',
        ])->delete();
    }
};
