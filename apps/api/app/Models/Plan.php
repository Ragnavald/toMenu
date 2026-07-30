<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'slug', 'price_cents', 'max_products',
    'allows_online_payment',
])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_products' => 'integer',
            'allows_online_payment' => 'boolean',
        ];
    }
}
