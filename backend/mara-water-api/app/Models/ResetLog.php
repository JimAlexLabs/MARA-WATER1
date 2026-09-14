<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ResetLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'performed_by', 'performed_by_email', 'backup_id',
        'tables_wiped', 'row_counts_before', 'created_at',
    ];

    protected $casts = [
        'tables_wiped' => 'array',
        'row_counts_before' => 'array',
        'created_at' => 'datetime',
    ];

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
