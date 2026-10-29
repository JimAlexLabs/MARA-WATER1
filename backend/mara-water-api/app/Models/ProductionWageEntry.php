<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductionWageEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_date', 'user_id', 'sku_id', 'kind', 'bales',
        'bottles_per_bale', 'wage_per_bottle', 'amount', 'created_by',
    ];

    protected $casts = [
        'work_date' => 'date',
        'bales' => 'decimal:2',
        'wage_per_bottle' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}
