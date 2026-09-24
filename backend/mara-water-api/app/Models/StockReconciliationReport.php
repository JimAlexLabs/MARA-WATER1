<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockReconciliationReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'period_type',
        'period_start',
        'period_end',
        'warehouse_id',
        'filename',
        'size_bytes',
        'payload',
        'generated_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'size_bytes' => 'integer',
    ];

    protected $hidden = ['payload'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
