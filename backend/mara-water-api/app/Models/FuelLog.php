<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FuelLog extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'fuel_logs';

    protected $fillable = [
        'vehicle_id',
        'date',
        'liters',
        'cost',
        'odometer',
        'receipt_photo_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'liters' => 'decimal:2',
        'cost' => 'decimal:2',
        'odometer' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
