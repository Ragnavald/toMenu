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
    'placed_at', 'confirmed_at', 'delivered_at', 'archived_at',
])]
class Order extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = [
        'pending_payment', 'confirmed', 'preparing',
        'ready', 'out_for_delivery', 'delivered', 'cancelled',
    ];

    /**
     * Como o cliente recebe o pedido.
     *
     * Só `delivery` envolve endereço e taxa; `pickup` e `dine_in` acontecem no
     * endereço da própria loja. A distinção entre os dois importa para a
     * operação — retirada sai pelo balcão, consumo no local vai para a mesa.
     */
    public const FULFILLMENTS = ['delivery', 'pickup', 'dine_in'];

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
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Só um pedido entregue sai do painel.
     *
     * Arquivar é dizer "a operação terminou com este", e isso só é verdade no
     * fim do fluxo. Um pedido em preparo sumindo da tela seria um pedido
     * esquecido na cozinha, então a regra vive no model — a rota e a tela
     * apenas a consultam, e não podem discordar dela.
     */
    public function canBeArchived(): bool
    {
        return $this->status === 'delivered' && $this->archived_at === null;
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

    /**
     * Move o pedido de status mantendo o pagamento coerente com ele.
     *
     * Sem pagamento pela plataforma, o dinheiro do pedido na entrega troca de
     * mãos no momento da entrega — não existe gateway para avisar depois. Como
     * o financeiro só conta `delivered` + `paid`, deixar o `payment_status` em
     * `pending` faria a receita ficar permanentemente vazia. Então o próprio
     * status carrega a informação de caixa, e a regra vive aqui para que o
     * painel, o histórico e qualquer rota futura não possam divergir dela.
     *
     * A sincronia vale nos dois sentidos, que é o que torna o número confiável
     * quando o lojista corrige um clique errado: voltar de "entregue" para
     * "em preparo" tira o pedido da receita de novo, e cancelar sempre tira.
     *
     * Pedidos pagos online (`stripe_*`) são o caso que NÃO se toca: ali quem
     * manda é o webhook, o dinheiro já entrou antes da entrega e continua tendo
     * entrado se o pedido for cancelado — mexer aqui apagaria um recebimento
     * real e transformaria um estorno, que é decisão do lojista, em efeito
     * colateral de um clique no painel.
     */
    public function moveToStatus(string $status): void
    {
        $attributes = ['status' => $status];

        if ($status === 'delivered' && $this->delivered_at === null) {
            $attributes['delivered_at'] = now();
        }

        if ($this->isPayOnDelivery()) {
            $attributes['payment_status'] = $status === 'delivered' ? 'paid' : 'pending';
        }

        $this->update($attributes);
    }
}
