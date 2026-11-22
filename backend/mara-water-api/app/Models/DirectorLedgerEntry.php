<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DirectorLedgerEntry extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'entry_date',
        'kind',
        'title',
        'explanation',
        'amount',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];
}
