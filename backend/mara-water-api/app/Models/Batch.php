<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Batch extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'code',
        'sku_id',
        'manufacture_date',
        'expiry_date',
        'planned_qty',
        'status',
        'opened_by',
        'closed_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
        'planned_qty' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}
