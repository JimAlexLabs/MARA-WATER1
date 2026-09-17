<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Round 2 Phase 3: the `customers` table has always had a deleted_at
 * column and CustomerController::destroy() always intended a soft
 * delete ("// Soft delete" comment), but this model never declared
 * SoftDeletes -- so deleted_at was silently dropped by mass-assignment
 * (not in $fillable, correctly) and nothing was ever actually deleted,
 * despite the endpoint reporting success. Adding SoftDeletes here also
 * means every existing Customer:: query (index, search, etc.) now
 * automatically excludes deleted customers via Eloquent's global scope
 * -- no other code had to change for reads to become correct too.
 * Customers were deliberately NOT hard-deletable: 5 of 6 foreign keys
 * referencing this table are ON DELETE NO ACTION (a real customer with
 * any order/debt/invoice/receipt would have blocked a hard delete
 * outright) and the sixth (price_agreements) is ON DELETE CASCADE
 * (would have silently deleted those rows) -- soft delete is correct
 * here, not just convenient.
 */
class Customer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

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
        'deleted_at' => 'datetime',
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
