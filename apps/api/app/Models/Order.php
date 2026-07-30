<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id', 'address_id', 'number', 'status', 'fulfillment',
    'payment_method', 'payment_status', 'subtotal_cents', 'delivery_fee_cents',
    'discount_cents', 'total_cents', 'stripe_payment_intent_id', 'notes',
    'placed_at', 'confirmed_at', 'delivered_at',
])]
class Order extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = [
        'pending_payment', 'confirmed', 'preparing',
        'ready', 'out_for_delivery', 'delivered', 'cancelled',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    /** Pagamento na entrega dispensa confirmação do gateway. */
    public function isPayOnDelivery(): bool
    {
        return in_array($this->payment_method, ['cash', 'card_on_delivery'], true);
    }
}
