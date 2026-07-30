<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'category_id', 'name', 'slug', 'description', 'price_cents',
    'promo_price_cents', 'image_url', 'is_available', 'position',
])]
class Product extends Model
{
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'promo_price_cents' => 'integer',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')
            ->withPivot('position')
            ->orderBy('product_modifier_group.position');
    }

    /** Preço efetivo em centavos, considerando promoção ativa. */
    public function effectivePriceCents(): int
    {
        return $this->promo_price_cents ?: $this->price_cents;
    }
}
