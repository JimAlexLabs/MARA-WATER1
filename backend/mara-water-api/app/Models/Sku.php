<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Sku extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'name',
        'size_liters',
        'unit',
        'expiry_days',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'size_liters' => 'decimal:2',
        'expiry_days' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }
}
