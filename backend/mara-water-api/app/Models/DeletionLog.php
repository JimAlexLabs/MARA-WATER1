<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeletionLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'entity', 'entity_id', 'action',
        'snapshot', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
