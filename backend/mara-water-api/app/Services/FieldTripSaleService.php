<?php

namespace App\Services;

use App\Models\DriverTrip;
use App\Models\DriverTripSale;
use App\Models\DriverTripSaleItem;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An admin cash sale that was really a driver's field round is copied
 * onto that day's trip so packed minus sold reconciles on the driver
 * dashboard. The order already took the stock, so the copy is marked
 * and must not be deducted or counted as sold a second time.
 */
class FieldTripSaleService
{
    public function attach(Order $order): string
    {
        if (! Schema::hasColumn('driver_trip_sales', 'source_order_id')) {
            return 'skipped: source_order_id missing';
        }

        $existing = DriverTripSale::where('source_order_id', $order->id)->whereNull('deleted_at')->first();
        if ($existing) {
            return 'already on trip '.$existing->driver_trip_id;
        }

        $order->loadMissing(['items.sku', 'customer']);
        $lines = $order->items->filter(fn ($item) => SaleCatalog::isBottled($item->sku) && (float) $item->qty > 0)->values();
        if ($lines->isEmpty()) {
            return 'no bottled lines';
        }

        $trip = $this->matchTrip($order, $lines);
        if (! $trip) {
            return 'no trip on '.$order->order_date;
        }

        DB::transaction(function () use ($order, $trip, $lines) {
            $customerId = $order->customer_id;
            if (! $customerId) {
                $buyer = trim((string) ($trip->route ?: 'Field')).' field cash';
                $customer = \App\Models\Customer::create([
                    'code' => 'CUST-'.strtoupper(\Illuminate\Support\Str::random(6)),
                    'name' => $buyer,
                    'type' => 'retail',
                    'phone' => 'N/A',
                    'address' => $trip->route,
                    'price_tier' => 'standard',
                    'status' => 'active',
                    'notes' => 'Field sale (auto)',
                    'created_by' => $order->created_by,
                    'updated_by' => $order->updated_by,
                ]);
                $customerId = $customer->id;
                $order->customer_id = $customerId;
                $order->save();
            }
            $amount = round($lines->sum(fn ($item) => (float) $item->qty * (float) $item->unit_price), 2);
            $method = $order->payment_method === 'mpesa' ? 'mpesa' : 'cash';
            $sale = $trip->sales()->create([
                'customer_id' => $customerId,
                'payment_method' => $method,
                'payment_status' => 'paid',
                'amount' => $amount,
                'mpesa_reference' => $order->payment_reference,
                'source_order_id' => $order->id,
                'created_by' => $order->created_by,
                'updated_by' => $order->updated_by,
            ]);

            foreach ($lines as $line) {
                $qty = (float) $line->qty;
                $price = (float) $line->unit_price;
                $sale->items()->create([
                    'sku_id' => $line->sku_id,
                    'qty_bales' => $qty,
                    'unit_price' => $price,
                    'line_total' => round($qty * $price, 2),
                ]);
            }

            $this->refreshReturned($trip->fresh('items'));
        });

        return 'attached to '.($trip->route ?: 'trip').' '.$trip->trip_date;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderItem>  $lines
     */
    private function matchTrip(Order $order, $lines): ?DriverTrip
    {
        $trips = DriverTrip::with('items')
            ->whereNull('deleted_at')
            ->whereDate('trip_date', $order->order_date)
            ->get();
        if ($trips->isEmpty()) {
            return null;
        }

        $hay = strtolower(trim(implode(' ', array_filter([
            $order->customer->name ?? '',
            $order->customer->address ?? '',
            $order->customer->notes ?? '',
            (string) ($order->notes ?? ''),
        ]))));

        $fitting = $trips->filter(fn (DriverTrip $trip) => $this->fits($trip, $lines))->values();
        $pool = $fitting->isNotEmpty() ? $fitting : $trips;

        $best = null;
        $bestScore = -1;
        foreach ($pool as $trip) {
            $route = strtolower(trim((string) $trip->route));
            $score = $pool->count() === 1 ? 1 : 0;
            if ($route !== '' && $hay !== '') {
                if (str_contains($hay, $route) || str_contains($route, $hay)) {
                    $score += 10;
                }
                foreach (preg_split('/[^a-z0-9]+/', $route) ?: [] as $token) {
                    if (strlen((string) $token) >= 4 && str_contains($hay, (string) $token)) {
                        $score += 5;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $trip;
            }
        }

        return $best;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderItem>  $lines
     */
    private function fits(DriverTrip $trip, $lines): bool
    {
        $sold = $this->soldBySku($trip->id);
        foreach ($lines as $line) {
            $item = $trip->items->firstWhere('sku_id', $line->sku_id);
            if (! $item) {
                return false;
            }
            $remain = (float) $item->qty_carried_bales - (float) ($sold[$line->sku_id] ?? 0);
            if ((float) $line->qty > $remain + 0.001) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,float> */
    private function soldBySku(string $tripId): array
    {
        return DriverTripSaleItem::query()
            ->whereHas('sale', fn ($q) => $q->where('driver_trip_id', $tripId)->whereNull('deleted_at'))
            ->selectRaw('sku_id, SUM(qty_bales) as sold')
            ->groupBy('sku_id')
            ->pluck('sold', 'sku_id')
            ->map(fn ($sold) => (float) $sold)
            ->all();
    }

    private function refreshReturned(DriverTrip $trip): void
    {
        $sold = $this->soldBySku($trip->id);
        foreach ($trip->items as $item) {
            $soldBales = (float) ($sold[$item->sku_id] ?? 0);
            $dispatched = (float) $item->qty_carried_bales;
            $item->update([
                'qty_returned_bales' => (int) max(0, floor($dispatched - $soldBales)),
                'qty_sold' => (int) min($soldBales, $dispatched),
            ]);
        }
    }
}
