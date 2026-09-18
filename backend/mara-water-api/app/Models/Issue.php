<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use HasUuids;

    protected $fillable = [
        'subject',
        'status',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages()
    {
        return $this->hasMany(IssueMessage::class)->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(IssueMessage::class)->latestOfMany();
    }
}
