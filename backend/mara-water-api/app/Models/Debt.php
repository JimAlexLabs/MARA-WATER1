<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Debt extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'principal',
        'balance',
        'days_overdue',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'balance' => 'decimal:2',
        'days_overdue' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
