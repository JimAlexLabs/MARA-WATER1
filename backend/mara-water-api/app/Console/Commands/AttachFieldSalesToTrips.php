<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use App\Services\FieldTripSaleService;
use Illuminate\Console\Command;

/**
 * One-time: the cash field sales keyed by admin from 9–11 Nov 2026
 * belong on the driver's trip for that day.
 */
class AttachFieldSalesToTrips extends Command
{
    protected $signature = 'sales:attach-field-to-trips';

    protected $description = 'Put admin-keyed field cash sales onto the matching driver trip and unlock driver cash';

    /** Orders the director keyed as warehouse cash that were field rounds. */
    private const ORDER_NOS = [
        'ORD-20261111-0001',
        'ORD-20261110-0005',
        'ORD-20261110-0004',
        'ORD-20261110-0001',
        'ORD-20261110-0002',
        'ORD-20261110-0003',
        'ORD-20261110-0008',
    ];

    public function handle(FieldTripSaleService $sync): int
    {
        $this->unlockDriverCash();

        $orders = Order::with(['items.sku', 'customer'])
            ->whereNull('deleted_at')
            ->whereIn('order_no', self::ORDER_NOS)
            ->orderBy('order_date')
            ->orderBy('order_no')
            ->get();

        foreach (self::ORDER_NOS as $no) {
            $order = $orders->firstWhere('order_no', $no);
            if (! $order) {
                $this->warn("  missing {$no}");
                continue;
            }
            $this->line("  {$no}: ".$sync->attach($order));
        }

        return 0;
    }

    private function unlockDriverCash(): void
    {
        $map = Setting::allAsMap();
        $locks = $map['manager_ui_locks'] ?? [];
        if (! is_array($locks)) {
            $locks = [];
        }
        $locks['driver_cash_payment'] = false;
        Setting::putMany([
            'manager_ui_locks' => $locks,
            'driver_allow_cash' => true,
        ]);

        $byUser = $map['manager_ui_locks_by_user'] ?? [];
        if (is_array($byUser)) {
            foreach ($byUser as $id => $partial) {
                if (is_array($partial) && array_key_exists('driver_cash_payment', $partial)) {
                    $byUser[$id]['driver_cash_payment'] = false;
                }
            }
            Setting::putMany(['manager_ui_locks_by_user' => $byUser]);
        }

        $this->info('Driver cash sales unlocked.');
    }
}
