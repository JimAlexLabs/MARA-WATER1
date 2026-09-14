<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Read all settings as a flat key => value map, decoding JSON scalars
     * (booleans/numbers) back to their native PHP type.
     */
    public static function allAsMap(): array
    {
        return static::all()->mapWithKeys(function ($row) {
            return [$row->key => json_decode($row->value)];
        })->toArray();
    }

    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }
    }
}
