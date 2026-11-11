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
            ->get(['i.id', 'i.qty', 'i.unit_price', 'o.order_no', 'o.order_date', 's.id as sku_id', 's.size_liters', 's.brand', 's.code']);

        foreach ($orderLines as $line) {
            $sku = \App\Models\Sku::find($line->sku_id);
            $size = SaleCatalog::refillSize($sku);
            if (! $size) {
                continue;
            }
            if ((string) $line->order_date < '2026-11-11') {
                continue;
            }
            $marker = "From {$line->order_no} · {$sku->code}";
            // Refill jerricans use the refill price list. A bale of 5L/10L/20L
            // stays a bale: the 10 bales of Premium 5L are KES 500 each, not
            // 40 jerricans at the 5L refill price.
            $bale = ! $sku->isRefill();
            $qty = (int) round((float) $line->qty);
            $price = ($bale && strtoupper((string) $sku->code) === 'PREM-5L')
                ? 500.0
                : $this->listPrice($sku->code, (float) $line->unit_price);
            if ($qty < 1) {
                continue;
            }
            $this->upsertTally($marker, [
                'tally_date' => $line->order_date,
                'size_liters' => (float) $size,
                'qty' => $qty,
                'unit_price' => $price,
                'qty_basis' => $bale ? 'bale' : 'jerrican',
                'notes' => $marker,
                'created_by' => $actor?->id,
            ]);
            $created++;
            $unit = $bale ? 'bales' : 'jerricans';
            $this->line("  {$line->order_no} {$sku->code} → {$qty} {$unit} x {$size}L @ {$price}");
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
            if ((string) $line->trip_date < '2026-11-11') {
                continue;
            }
            $marker = "From field {$line->trip_date} {$line->route} · {$sku->code}";
            $bale = ! $sku->isRefill();
            $qty = (int) round((float) $line->qty_bales);
            if ($qty < 1) {
                continue;
            }
            $price = $this->listPrice($sku->code, 0);
            $this->upsertTally($marker, [
                'tally_date' => $line->trip_date,
                'size_liters' => (float) $size,
                'qty' => $qty,
                'unit_price' => $price,
                'qty_basis' => $bale ? 'bale' : 'jerrican',
                'notes' => $marker,
                'created_by' => $actor?->id,
            ]);
            $created++;
            $this->line("  trip {$line->trip_date} {$line->route} {$sku->code} → {$qty} x {$size}L @ {$price}");
        }

        $this->info("Refill lines priced: {$created}");

        return 0;
    }

    /** @param  array<string,mixed>  $values */
    private function upsertTally(string $marker, array $values): void
    {
        $existing = RefillTally::where('notes', $marker)->first();
        if ($existing) {
            $existing->update($values);

            return;
        }
        RefillTally::create($values);
    }

    private function listPrice(string $code, float $fallback): float
    {
        $price = DB::table('price_list_items as i')
            ->join('skus as s', 's.id', '=', 'i.sku_id')
            ->join('price_lists as p', 'p.id', '=', 'i.price_list_id')
            ->where('p.is_default', true)
            ->where('s.code', $code)
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->value('i.unit_price');

        return $price !== null ? (float) $price : $fallback;
    }
}
