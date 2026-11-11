<?php

namespace App\Services;

use App\Models\Sku;

/**
 * Bottled sales are 0.5L, 1L and 1.5L.
 * 5L, 10L and 20L are customer refills and stay off the sales lists.
 */
class SaleCatalog
{
    public static function isBottled(?Sku $sku): bool
    {
        if (! $sku || $sku->isRefill()) {
            return false;
        }
        $size = round((float) $sku->size_liters, 2);

        return in_array($size, [0.5, 1.0, 1.5], true);
    }

    /** "5", "10" or "20" when this line belongs on the refill tally. */
    public static function refillSize(?Sku $sku): ?string
    {
        if (! $sku) {
            return null;
        }
        $size = round((float) $sku->size_liters, 2);
        foreach ([5.0 => '5', 10.0 => '10', 20.0 => '20'] as $liters => $label) {
            if (abs($size - $liters) < 0.011) {
                return $label;
            }
        }

        return null;
    }
}
