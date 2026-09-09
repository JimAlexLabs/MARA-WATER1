<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Vehicle extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reg_no',
        'make',
        'model',
        'year',
        'capacity',
        'active',
        'insurance_expiry',
        'inspection_expiry',
        'speed_gov_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'capacity' => 'integer',
        'active' => 'boolean',
        'insurance_expiry' => 'date',
        'inspection_expiry' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function manifests()
    {
        return $this->hasMany(Manifest::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
