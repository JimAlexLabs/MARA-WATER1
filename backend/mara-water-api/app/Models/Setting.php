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
        // Always decode JSON as associative arrays. Without the `true` flag,
        // objects like manager_ui_locks become stdClass and callers that
        // check is_array() silently fall back to defaults (locks stuck on).
        return static::all()->mapWithKeys(function ($row) {
            return [$row->key => json_decode($row->value, true)];
        })->toArray();
    }

    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }
    }
}
