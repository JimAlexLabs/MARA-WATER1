<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EquipmentItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'category',
        'name',
        'unit',
        'qty_on_hand',
        'minimum_required',
        'condition',
        'last_service_date',
        'next_service_due',
        'calibration_status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty_on_hand' => 'integer',
        'minimum_required' => 'integer',
        'last_service_date' => 'date',
        'next_service_due' => 'date',
        'deleted_at' => 'datetime',
    ];
}
