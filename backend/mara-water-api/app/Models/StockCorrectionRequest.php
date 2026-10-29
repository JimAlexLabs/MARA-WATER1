<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockCorrectionRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'requested_by', 'status', 'payload', 'reason',
        'reviewed_by', 'reviewed_at', 'stock_move_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
