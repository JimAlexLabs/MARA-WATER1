<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Audit extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'action',
        'entity',
        'entity_id',
        'diff_json',
        'meta_json',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'diff_json' => 'array',
        'meta_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that performed the audit.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include audits by user.
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include audits by entity.
     */
    public function scopeByEntity($query, $entity, $entityId = null)
    {
        $query->where('entity', $entity);
        
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }
        
        return $query;
    }

    /**
     * Scope a query to only include audits by action.
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }
}
