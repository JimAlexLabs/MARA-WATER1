<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Round 2 Phase 6: `days_overdue` used to be a physical column set once
 * by a BEFORE INSERT trigger and never touched again -- frozen at
 * whatever it was the moment the debt was created, forever (a debt
 * inserted on-time would show "0 days overdue" months later even after
 * genuinely becoming overdue). It's a live accessor now, computed from
 * expected_repayment_date (the tighter, deliberate promise this phase
 * adds, max 7 days) falling back to the linked invoice's due_date (Net
 * 30) for older debts that predate this column. A fully-paid debt
 * (balance <= 0) is never "overdue" regardless of the date.
 */
class Debt extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'signatory',
        'expected_repayment_date',
        'principal',
        'balance',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'balance' => 'decimal:2',
        'expected_repayment_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function getDaysOverdueAttribute(): int
    {
        if ((float) $this->balance <= 0) {
            return 0;
        }

        $dueDate = $this->expected_repayment_date ?? $this->invoice?->due_date;
        if (!$dueDate) {
            return 0;
        }

        $today = now()->startOfDay();
        $due = $dueDate instanceof \Carbon\Carbon ? $dueDate->copy()->startOfDay() : \Carbon\Carbon::parse($dueDate)->startOfDay();

        // Carbon 3's diffInDays() returns a signed value by default (not
        // absolute like older Carbon) -- abs() here rather than trusting
        // the sign, since $today->diffInDays($due) came back -3 for a due
        // date 3 days in the past when this was first tested live.
        return $today->gt($due) ? abs((int) $today->diffInDays($due)) : 0;
    }
}
