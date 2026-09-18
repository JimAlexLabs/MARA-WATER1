<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Round 3 Phase 2: a record of every Director-only "unlock" action on a
 * locked trip -- who, when, which stage transition was reversed, why,
 * and a full snapshot of the trip+items+sales state at the moment of
 * unlock (so what actually changed after re-submission is always
 * reconstructable, even though this doesn't track every individual
 * field's old/new value separately).
 */
class DriverTripUnlock extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'driver_trip_id',
        'from_status',
        'to_status',
        'reason',
        'snapshot',
        'unlocked_by',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (DriverTripUnlock $unlock) {
            if (! $unlock->created_at) {
                $unlock->created_at = now();
            }
        });
    }

    public function trip()
    {
        return $this->belongsTo(DriverTrip::class, 'driver_trip_id');
    }

    public function unlockedBy()
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
