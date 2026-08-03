<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'slug', 'price_cents', 'stripe_price_id', 'max_products',
    'allows_online_payment', 'allows_orders', 'allows_delivery', 'sort_order',
])]
class Plan extends Model
{
    use HasFactory;

    /** Plano completo: cardápio, pedidos, entrega e pagamento online. */
    public const PRO = 'pro';

    /** Vitrine: só cardápio. Sem carrinho, sem checkout, sem operação. */
    public const MENU_ONLY = 'cardapio';

    /**
     * Planos contratáveis no cadastro público.
     *
     * O cadastro valida contra esta lista em vez de aceitar qualquer slug da
     * tabela: um plano interno ou descontinuado não deve virar contratável só
     * por existir uma linha no banco.
     */
    public const PUBLIC_SLUGS = [self::MENU_ONLY, self::PRO];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_products' => 'integer',
            'sort_order' => 'integer',
            'allows_online_payment' => 'boolean',
            'allows_orders' => 'boolean',
            'allows_delivery' => 'boolean',
        ];
    }

    /**
     * O plano é somente cardápio?
     *
     * Derivado da capacidade, não do slug: quem decide é `allows_orders`, então
     * um plano futuro que também não aceite pedidos entra aqui de graça.
     */
    public function isMenuOnly(): bool
    {
        return ! $this->allows_orders;
    }
}
