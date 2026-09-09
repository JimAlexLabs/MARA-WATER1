<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'phone',
        'password_hash',
        'first_name',
        'last_name',
        'avatar_url',
        'status',
        'role_id',
        'department_id',
        'two_factor_secret',
        'two_factor_enabled',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password_hash',
        'two_factor_secret',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'two_factor_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the password for the user.
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Get the user's full name.
     */
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Check if user is Director (ADMIN).
     */
    public function isDirector()
    {
        return $this->role && $this->role->code === 'ADMIN';
    }

    /**
     * Check if user is active.
     */
    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the department that owns the user.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the user who created this user.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this user.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user sessions for the user.
     */
    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Get the audits for the user.
     */
    public function audits()
    {
        return $this->hasMany(Audit::class);
    }

    /**
     * Get the water tests recorded by the user.
     */
    public function waterTests()
    {
        return $this->hasMany(WaterTest::class, 'recorded_by');
    }

    /**
     * Get the water tests verified by the user (RIC).
     */
    public function verifiedWaterTests()
    {
        return $this->hasMany(WaterTest::class, 'ric_verified_by');
    }

    /**
     * Get the batches opened by the user.
     */
    public function openedBatches()
    {
        return $this->hasMany(Batch::class, 'opened_by');
    }

    /**
     * Get the batches closed by the user.
     */
    public function closedBatches()
    {
        return $this->hasMany(Batch::class, 'closed_by');
    }

    /**
     * Get the packaging runs by the user.
     */
    public function packagingRuns()
    {
        return $this->hasMany(PackagingRun::class, 'run_by');
    }

    /**
     * Get the orders created by the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'sales_officer_id');
    }

    /**
     * Get the manifests where user is driver.
     */
    public function driverManifests()
    {
        return $this->hasMany(Manifest::class, 'driver_id');
    }

    /**
     * Get the manifests where user is sales officer.
     */
    public function salesOfficerManifests()
    {
        return $this->hasMany(Manifest::class, 'sales_officer_id');
    }

    /**
     * Get the attendances for the user.
     */
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the tasks assigned to the user.
     */
    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * Get the tasks created by the user.
     */
    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the files uploaded by the user.
     */
    public function files()
    {
        return $this->hasMany(File::class, 'uploaded_by');
    }

    /**
     * Get the voice notes by the user.
     */
    public function voiceNotes()
    {
        return $this->hasMany(VoiceNote::class);
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include users by role.
     */
    public function scopeByRole($query, $roleCode)
    {
        return $query->whereHas('role', function ($q) use ($roleCode) {
            $q->where('code', $roleCode);
        });
    }

    /**
     * Scope a query to only include users by department.
     */
    public function scopeByDepartment($query, $departmentCode)
    {
        return $query->whereHas('department', function ($q) use ($departmentCode) {
            $q->where('code', $departmentCode);
        });
    }
}
