<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'basic_salary', 'house_allowance', 'telephone_allowance',
        'other_allowance', 'terms_of_employment', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'house_allowance' => 'decimal:2',
        'telephone_allowance' => 'decimal:2',
        'other_allowance' => 'decimal:2',
    ];
}
