<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'provider', 'provider_payment_id', 'amount_cents',
    'application_fee_cents', 'status', 'raw_payload',
])]
class Payment extends Model
{
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'application_fee_cents' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
