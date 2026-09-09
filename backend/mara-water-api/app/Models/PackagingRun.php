<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PackagingRun extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'batch_id',
        'sku_id',
        'run_start',
        'run_end',
        'good_qty',
        'scrap_qty',
        'downtime_minutes',
        'notes',
        'run_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'run_start' => 'datetime',
        'run_end' => 'datetime',
        'good_qty' => 'integer',
        'scrap_qty' => 'integer',
        'downtime_minutes' => 'integer',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    public function runBy()
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Accessors
    public function getTotalQtyAttribute()
    {
        return $this->good_qty + $this->scrap_qty;
    }

    public function getYieldPercentageAttribute()
    {
        $totalQty = $this->total_qty;
        return $totalQty > 0 ? ($this->good_qty / $totalQty) * 100 : 0;
    }

    public function getDurationMinutesAttribute()
    {
        if (!$this->run_start || !$this->run_end) {
            return null;
        }
        return $this->run_start->diffInMinutes($this->run_end);
    }

    public function getStatusAttribute()
    {
        return $this->run_end ? 'completed' : 'in_progress';
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('run_end');
    }

    public function scopeInProgress($query)
    {
        return $query->whereNull('run_end');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('run_start', [$startDate, $endDate]);
    }

    public function scopeBySku($query, $skuId)
    {
        return $query->where('sku_id', $skuId);
    }

    public function scopeByBatch($query, $batchId)
    {
        return $query->where('batch_id', $batchId);
    }
}
