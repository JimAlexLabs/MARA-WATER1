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
        'theme',
        'status',
        'role_id',
        'department_id',
        'id_number',
        'staff_number',
        'address',
        'kra_pin',
        'nssf_number',
        'shif_number',
        'date_of_birth',
        'terms_of_employment',
        'employment_date',
        'salary',
        'house_allowance',
        'bank_name',
        'bank_branch',
        'bank_account_number',
        'bank_code',
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

    protected $appends = ['gross_salary'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'two_factor_enabled' => 'boolean',
        'employment_date' => 'date',
        'date_of_birth' => 'date',
        'salary' => 'decimal:2',
        'house_allowance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Whether this employee record has app login access at all.
     */
    public function getHasLoginAttribute(): bool
    {
        return !empty($this->password_hash);
    }

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
     * Round 2 Phase 4: `salary` has always meant Basic Salary here (it
     * predates this phase) -- this alias reads better in payroll code
     * without a risky rename of a column other parts of the app already
     * depend on.
     */
    public function getBasicSalaryAttribute()
    {
        return $this->salary;
    }

    /**
     * Basic Salary + House Allowance. Spec: "calculated not typed" --
     * this is why it's an accessor, not a stored column.
     */
    public function getGrossSalaryAttribute(): float
    {
        return (float) $this->salary + (float) $this->house_allowance;
    }

    public function staffLoans()
    {
        return $this->hasMany(StaffLoan::class);
    }

    /**
     * Active loans/advances with an outstanding balance -- what the next
     * payroll run should deduct against.
     */
    public function activeStaffLoans()
    {
        return $this->staffLoans()->where('status', 'active')->where('balance', '>', 0);
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
