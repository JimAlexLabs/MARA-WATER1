<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Customer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'name',
        'contact_person',
        'type',
        'phone',
        'email',
        'address',
        'route_id',
        'price_tier',
        'preferred_products',
        'typical_order_size',
        'payment_terms',
        'notes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['debtor_balance'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function debts()
    {
        return $this->hasMany(Debt::class);
    }

    /**
     * Outstanding balance from the debtor ledger (Phase 9 will post real
     * credit-sale entries here; this already reads whatever's there).
     */
    public function getDebtorBalanceAttribute(): float
    {
        // Use the loaded relation when eager-loaded (list views) to avoid
        // an N+1; fall back to a direct query for a single show() call.
        if ($this->relationLoaded('debts')) {
            return (float) $this->debts->sum('balance');
        }
        return (float) $this->debts()->sum('balance');
    }
}
