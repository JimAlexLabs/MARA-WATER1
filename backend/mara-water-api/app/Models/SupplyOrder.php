<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplyOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'kind', 'supplier', 'reference_no', 'order_date', 'lead_business_days', 'expected_date',
        'status', 'received_date', 'lines', 'vat_applies', 'goods_cost', 'vat_amount',
        'transport_cost', 'total_cost', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'order_date' => 'date:Y-m-d',
        'expected_date' => 'date:Y-m-d',
        'received_date' => 'date:Y-m-d',
        'lines' => 'array',
        'vat_applies' => 'boolean',
        'goods_cost' => 'float',
        'vat_amount' => 'float',
        'transport_cost' => 'float',
        'total_cost' => 'float',
        'lead_business_days' => 'integer',
    ];

    /** @return array<string,mixed> */
    public function toApi(): array
    {
        $overdue = in_array($this->status, ['placed', 'in_transit'], true)
            && $this->expected_date
            && $this->expected_date->lt(now()->startOfDay());

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'supplier' => $this->supplier,
            'reference_no' => $this->reference_no,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'received_date' => $this->received_date?->toDateString(),
            'lead_business_days' => $this->lead_business_days,
            'status' => $this->status,
            'overdue' => $overdue,
            'lines' => $this->lines ?? [],
            'vat_applies' => $this->vat_applies,
            'goods_cost' => $this->goods_cost,
            'vat_amount' => $this->vat_amount,
            'transport_cost' => $this->transport_cost,
            'total_cost' => $this->total_cost,
            'notes' => $this->notes,
        ];
    }
}
