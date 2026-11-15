<?php

namespace App\Support;

use Carbon\Carbon;

/** Dates people read: day, then month, then year. */
class DisplayDate
{
    public static function day(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)->timezone('Africa/Nairobi')->format('d/m/Y');
    }

    public static function dayTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::parse($value)->timezone('Africa/Nairobi')->format('d/m/Y H:i');
    }
}
