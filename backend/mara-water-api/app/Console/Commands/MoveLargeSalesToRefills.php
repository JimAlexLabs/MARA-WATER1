<?php

namespace App\Console\Commands;

use App\Models\RefillTally;
use App\Models\User;
use App\Services\SaleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 5L, 10L and 20L already sold are refills. Copy them onto the admin
 * refill tally. The sale rows stay so finished-stock math is unchanged,
 * and the sales lists simply stop showing them.
 */
class MoveLargeSalesToRefills extends Command
{
    protected $signature = 'sales:move-large-to-refills';

    protected $description = 'Copy already-sold 5L, 10L and 20L lines onto the admin refill tally';

    public function handle(): int
    {
        $actor = User::whereHas('role', fn ($q) => $q->where('access_tier', 'director'))->first();
        $created = 0;

        $orderLines = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('skus as s', 's.id', '=', 'i.sku_id')
            ->whereNull('o.deleted_at')
            ->whereNull('i.deleted_at')
            ->get(['i.id', 'i.qty', 'o.order_no', 'o.order_date', 's.id as sku_id', 's.size_liters', 's.brand', 's.code']);

        foreach ($orderLines as $line) {
            $sku = \App\Models\Sku::find($line->sku_id);
            $size = SaleCatalog::refillSize($sku);
            if (! $size) {
                continue;
            }
            $marker = "From {$line->order_no} · {$sku->code}";
            if (RefillTally::where('notes', $marker)->exists()) {
                continue;
            }
            $qty = $sku->isRefill()
                ? (int) round((float) $line->qty)
                : (int) round((float) $line->qty * $sku->bottlesPerBale());
            if ($qty < 1) {
                continue;
            }
            RefillTally::create([
                'tally_date' => $line->order_date,
                'size_liters' => (float) $size,
                'qty' => $qty,
                'notes' => $marker,
                'created_by' => $actor?->id,
            ]);
            $created++;
            $this->line("  {$line->order_no} {$sku->code} → {$qty} x {$size}L");
        }

        $tripLines = DB::table('driver_trip_sale_items as i')
            ->join('driver_trip_sales as sa', 'sa.id', '=', 'i.driver_trip_sale_id')
            ->join('driver_trips as t', 't.id', '=', 'sa.driver_trip_id')
            ->join('skus as s', 's.id', '=', 'i.sku_id')
            ->whereNull('sa.deleted_at')
            ->whereNull('t.deleted_at')
            ->get(['i.id', 'i.qty_bales', 't.trip_date', 't.route', 's.id as sku_id']);

        foreach ($tripLines as $line) {
            $sku = \App\Models\Sku::find($line->sku_id);
            $size = SaleCatalog::refillSize($sku);
            if (! $size) {
                continue;
            }
            $marker = "From field {$line->trip_date} {$line->route} · {$sku->code}";
            if (RefillTally::where('notes', $marker)->exists()) {
                continue;
            }
            $qty = $sku->isRefill()
                ? (int) round((float) $line->qty_bales)
                : (int) round((float) $line->qty_bales * $sku->bottlesPerBale());
            if ($qty < 1) {
                continue;
            }
            RefillTally::create([
                'tally_date' => $line->trip_date,
                'size_liters' => (float) $size,
                'qty' => $qty,
                'notes' => $marker,
                'created_by' => $actor?->id,
            ]);
            $created++;
            $this->line("  trip {$line->trip_date} {$line->route} {$sku->code} → {$qty} x {$size}L");
        }

        $this->info("Refill lines copied: {$created}");

        return 0;
    }
}
