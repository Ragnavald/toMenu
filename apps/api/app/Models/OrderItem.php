<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot imutável do item no momento da compra.
 *
 * product_name e unit_price_cents são copiados, não lidos via join. Se o
 * restaurante reajustar o preço amanhã, o pedido de ontem permanece intacto —
 * requisito contábil, não detalhe de implementação.
 */
#[Fillable([
    'order_id', 'product_id', 'product_name', 'unit_price_cents',
    'quantity', 'modifiers_snapshot', 'total_cents',
])]
class OrderItem extends Model
{
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'total_cents' => 'integer',
            'modifiers_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
