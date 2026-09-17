<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'month', 'status', 'run_by', 'finalized_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'month' => 'date',
        'finalized_at' => 'datetime',
    ];

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function runBy()
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
