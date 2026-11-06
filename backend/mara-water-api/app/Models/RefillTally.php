<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RefillTally extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'tally_date',
        'size_liters',
        'qty',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'tally_date' => 'date',
        'size_liters' => 'float',
        'qty' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
