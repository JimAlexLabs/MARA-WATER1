<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectorRestartPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'is_active',
        'assumptions',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'assumptions' => 'array',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
