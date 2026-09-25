<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompetitorPrice extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'competitor_company_id',
        'sku_id',
        'unit_price',
        'effective_date',
        'notes',
        'logged_by',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'effective_date' => 'date',
        'deleted_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(CompetitorCompany::class, 'competitor_company_id');
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
