<?php

namespace App\Services;

use App\Models\Sku;

/**
 * Standard bale prices, with Masara and Nyatike KES 10 lower.
 * Nothing in those three sizes sells under the floor.
 */
class MarketPrice
{
    /** @var array<string,float> */
    private const STANDARD = [
        '0.50' => 370.0,
        '1.00' => 370.0,
        '1.50' => 380.0,
    ];

    /** @var array<string,float> */
    private const FLOOR = [
        '0.50' => 360.0,
        '1.00' => 360.0,
        '1.50' => 370.0,
    ];

    public static function sizeKey(?Sku $sku): ?string
    {
        if (! $sku || $sku->isRefill() || stripos((string) $sku->code, 'CUSTOM') !== false) {
            return null;
        }
        $key = number_format(round((float) $sku->size_liters, 2), 2, '.', '');

        return isset(self::FLOOR[$key]) ? $key : null;
    }

    public static function isDiscountMarket(?string $place): bool
    {
        $place = strtolower((string) $place);

        return str_contains($place, 'masara') || str_contains($place, 'nyatike');
    }

    /**
     * List price, minus 10 in Masara / Nyatike, never under the floor.
     */
    public static function adjust(?Sku $sku, ?float $listPrice, ?string $place): ?float
    {
        if ($listPrice === null) {
            return null;
        }
        $key = self::sizeKey($sku);
        if ($key === null) {
            return $listPrice;
        }
        $price = $listPrice;
        if (self::isDiscountMarket($place)) {
            $price -= 10;
        }

        return max(self::FLOOR[$key], $price);
    }

    public static function floorError(?Sku $sku, float $price): ?string
    {
        $key = self::sizeKey($sku);
        if ($key === null) {
            return null;
        }
        $floor = self::FLOOR[$key];
        if ($price + 0.001 < $floor) {
            return '0.5L and 1L cannot be below KES 360. 1.5L cannot be below KES 370.';
        }

        return null;
    }
}
