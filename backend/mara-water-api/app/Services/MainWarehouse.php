<?php

namespace App\Services;

use App\Models\Warehouse;

/**
 * Single plant warehouse picker. Production, sales, trips and finished-goods
 * checks must agree — mixing MAIN vs WH-MAIN made sellable stock look empty
 * while the production book still showed bales.
 */
class MainWarehouse
{
    public static function id(?string $preferred = null): ?string
    {
        if ($preferred) {
            return $preferred;
        }

        return Warehouse::whereNull('deleted_at')->where('code', 'WH-MAIN')->value('id')
            ?: Warehouse::whereNull('deleted_at')->where('code', 'MAIN')->value('id')
            ?: Warehouse::whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('code', 'like', '%MAIN%')
                        ->orWhere('name', 'like', '%Main%')
                        ->orWhere('name', 'like', '%Warehouse%');
                })
                ->orderBy('code')
                ->value('id')
            ?: Warehouse::whereNull('deleted_at')->orderBy('name')->value('id');
    }

    /** Every warehouse id that may hold plant finished goods. */
    public static function ids(): array
    {
        $main = self::id();
        $ids = Warehouse::whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereIn('code', ['WH-MAIN', 'MAIN'])
                    ->orWhere('code', 'like', '%MAIN%')
                    ->orWhere('name', 'like', '%Main%');
            })
            ->pluck('id')
            ->all();
        if ($main) {
            $ids[] = $main;
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
