<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PettyCashEntry extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'entry_date',
        'mpesa_reference',
        'account_id',
        'description',
        'requestor_id',
        'requestor_name',
        'amount_in',
        'amount_out',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount_in' => 'decimal:2',
        'amount_out' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function requestor()
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }
}
