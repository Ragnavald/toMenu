<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'min_select', 'max_select', 'is_required',
    'source', 'source_category_id', 'pricing_rule',
])]
class ModifierGroup extends Model
{
    use BelongsToTenant, HasFactory;

    /** Opções vindas dos modifiers do próprio grupo (borda, adicional). */
    public const SOURCE_LIST = 'list';

    /** Opções vindas dos produtos de uma categoria (sabores de pizza). */
    public const SOURCE_CATEGORY = 'category';

    public const SOURCES = [self::SOURCE_LIST, self::SOURCE_CATEGORY];

    /**
     * Regras de preço para escolha múltipla.
     *
     * 'sum' é o comportamento histórico e continua sendo o default. As outras
     * duas existem para o caso meio a meio, onde somar dois sabores inteiros
     * cobraria duas pizzas.
     */
    public const PRICING_RULES = ['sum', 'highest', 'average'];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('position');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group');
    }

    /** Categoria que abastece as opções quando source = 'category'. */
    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'source_category_id');
    }

    /**
     * Produtos ofertados como opção, com preço próprio opcional.
     *
     * Existe em paralelo à sourceCategory: a categoria define o conjunto, esta
     * tabela guarda apenas os overrides de preço e ordem. Um sabor sem linha
     * aqui é ofertado pelo próprio preço.
     */
    public function optionProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_group_product')
            ->withPivot(['price_cents', 'position'])
            ->orderBy('modifier_group_product.position');
    }

    public function isComposed(): bool
    {
        return $this->source === self::SOURCE_CATEGORY;
    }

    /**
     * Aplica a regra de preço do grupo sobre os valores escolhidos.
     *
     * Recebe já os centavos de cada opção selecionada e devolve o quanto o
     * grupo acrescenta ao item. Centralizado aqui para que o cardápio, o
     * carrinho e o pedido não possam divergir na conta.
     *
     * @param  array<int,int>  $cents
     */
    public function applyPricing(array $cents): int
    {
        if ($cents === []) {
            return 0;
        }

        return match ($this->pricing_rule) {
            'highest' => max($cents),
            // intdiv trunca; usar round evitaria perder o centavo do caso
            // (5900+7200)/2, mas favoreceria o cliente de forma inconsistente
            // com o resto do sistema, que sempre trabalha em centavos inteiros.
            'average' => (int) round(array_sum($cents) / count($cents)),
            default => array_sum($cents),
        };
    }
}
