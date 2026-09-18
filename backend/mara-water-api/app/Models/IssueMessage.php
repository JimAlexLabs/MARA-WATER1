<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IssueMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'issue_id',
        'sender_id',
        'body',
        'photo_path',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (IssueMessage $message) {
            if (! $message->created_at) {
                $message->created_at = now();
            }
        });
    }

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
