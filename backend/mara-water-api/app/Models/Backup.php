<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Backup extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'created_by', 'reason', 'table_row_counts', 'size_bytes', 'payload', 'created_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'table_row_counts' => 'array',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
