<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffLoan extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'type', 'principal', 'monthly_deduction', 'balance',
        'issued_date', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'monthly_deduction' => 'decimal:2',
        'balance' => 'decimal:2',
        'issued_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
