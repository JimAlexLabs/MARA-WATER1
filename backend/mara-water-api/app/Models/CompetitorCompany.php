<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompetitorCompany extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function prices()
    {
        return $this->hasMany(CompetitorPrice::class);
    }
}
