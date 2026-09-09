<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WaterTest extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'test_type',
        'recorded_at',
        'ph',
        'tds',
        'chlorine',
        'unit_notes',
        'location_text',
        'warehouse_id',
        'photo_id',
        'recorded_by',
        'ric_verified_by',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'ph' => 'decimal:2',
        'tds' => 'decimal:2',
        'chlorine' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'ric_verified_by');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
