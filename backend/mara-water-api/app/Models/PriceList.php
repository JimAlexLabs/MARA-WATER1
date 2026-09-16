<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PriceList extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'is_default',
        'valid_from',
        'valid_to',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'deleted_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
