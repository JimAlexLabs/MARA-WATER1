<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Attendance extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'shift_id',
        'date',
        'clock_in_time',
        'clock_out_time',
        'break_start_time',
        'break_end_time',
        'total_hours',
        'overtime_hours',
        'status',
        'notes',
        'location',
        'photo_url',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in_time' => 'datetime:H:i:s',
        'clock_out_time' => 'datetime:H:i:s',
        'break_start_time' => 'datetime:H:i:s',
        'break_end_time' => 'datetime:H:i:s',
        'total_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Accessors
    public function getIsLateAttribute()
    {
        return $this->clock_in_time && $this->clock_in_time->format('H:i:s') > '08:00:00';
    }

    public function getIsEarlyDepartureAttribute()
    {
        return $this->clock_out_time && $this->clock_out_time->format('H:i:s') < '17:00:00';
    }

    public function getNetWorkHoursAttribute()
    {
        if (!$this->total_hours) {
            return 0;
        }

        $breakHours = 0;
        if ($this->break_start_time && $this->break_end_time) {
            $breakStart = \Carbon\Carbon::parse($this->break_start_time);
            $breakEnd = \Carbon\Carbon::parse($this->break_end_time);
            $breakHours = $breakStart->diffInHours($breakEnd, true);
        }

        return max(0, $this->total_hours - $breakHours);
    }

    public function getWorkEfficiencyAttribute()
    {
        if (!$this->total_hours || $this->total_hours < 8) {
            return 0;
        }

        // Calculate efficiency based on hours worked vs standard 8-hour day
        return min(100, ($this->total_hours / 8) * 100);
    }

    // Scopes
    public function scopePresent($query)
    {
        return $query->where('status', 'present');
    }

    public function scopeAbsent($query)
    {
        return $query->where('status', 'absent');
    }

    public function scopeLate($query)
    {
        return $query->whereTime('clock_in_time', '>', '08:00:00');
    }

    public function scopeByDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByDepartment($query, $departmentId)
    {
        return $query->whereHas('user.department', function($q) use ($departmentId) {
            $q->where('id', $departmentId);
        });
    }

    public function scopeToday($query)
    {
        return $query->whereDate('date', now()->toDateString());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('date', [
            now()->startOfWeek()->toDateString(),
            now()->endOfWeek()->toDateString()
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereBetween('date', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        ]);
    }

    public function scopeWithOvertime($query)
    {
        return $query->where('overtime_hours', '>', 0);
    }

    public function scopeComplete($query)
    {
        return $query->whereNotNull('clock_in_time')
                    ->whereNotNull('clock_out_time');
    }

    public function scopeIncomplete($query)
    {
        return $query->whereNotNull('clock_in_time')
                    ->whereNull('clock_out_time');
    }
}
