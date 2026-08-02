<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Criação de pedido com precificação autoritativa no servidor.
 *
 * O cliente envia apenas IDs e quantidades. Todo valor é relido do banco: o
 * preço que chega na request é ignorado por completo. Aceitá-lo permitiria
 * comprar qualquer item por um centavo — falha trivial de explorar e comum em
 * implementações que confiam no payload do carrinho.
 */
class OrderService
{
    public function __construct(
        private OrderNumberGenerator $numbers,
        private ModifierOptionResolver $options,
    ) {}

    public function create(Tenant $tenant, array $data): Order
    {
        return DB::transaction(function () use ($tenant, $data) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer']['phone']],
                ['name' => $data['customer']['name'], 'email' => $data['customer']['email'] ?? null],
            );

            $priced = $this->priceItems($data['items']);
            $subtotal = array_sum(array_column($priced, 'total_cents'));

            $fulfillment = $data['fulfillment'] ?? 'delivery';
            $this->assertFulfillmentAllowed($tenant, $fulfillment);
            $deliveryFee = $fulfillment === 'delivery' ? $this->deliveryFee($tenant) : 0;

            $method = $data['payment_method'];
            $this->assertPaymentMethodAllowed($tenant, $method);

            $address = null;
            if ($fulfillment === 'delivery') {
                $address = $customer->addresses()->create($data['address']);
            }

            $order = Order::create([
                'customer_id' => $customer->id,
                'address_id' => $address?->id,
                'number' => $this->numbers->next($tenant),
                // Pagamento na entrega já entra confirmado; online aguarda o webhook.
                'status' => $this->isPayOnDelivery($method) ? 'confirmed' : 'pending_payment',
                'fulfillment' => $fulfillment,
                'payment_method' => $method,
                'payment_status' => 'pending',
                'subtotal_cents' => $subtotal,
                'delivery_fee_cents' => $deliveryFee,
                'discount_cents' => 0,
                'total_cents' => $subtotal + $deliveryFee,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
                'confirmed_at' => $this->isPayOnDelivery($method) ? now() : null,
            ]);

            $order->items()->createMany($priced);

            return $order->load('items', 'customer', 'address');
        });
    }

    /**
     * Relê preços do banco e monta o snapshot de cada item.
     *
     * @return array<int,array<string,mixed>>
     */
    private function priceItems(array $items): array
    {
        $productIds = array_column($items, 'product_id');

        // Global scope garante que só produtos DESTE tenant sejam encontrados;
        // um product_id de outra loja simplesmente não resolve.
        //
        // Os grupos vêm junto porque a precificação agora depende deles: a
        // regra ('sum', 'highest') e os limites de seleção moram no grupo, e
        // não dá para confiar no que o cliente mandou sobre nenhum dos dois.
        $products = Product::whereIn('id', $productIds)
            ->where('is_available', true)
            ->with(['modifierGroups.modifiers', 'modifierGroups.optionProducts'])
            ->get()
            ->keyBy('id');

        $priced = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => 'Um dos itens não está mais disponível.',
                ]);
            }

            $chosenIds = array_map('intval', $item['modifier_ids'] ?? []);

            [$extraCents, $snapshot] = $this->priceGroups($product, $chosenIds);

            $unit = $product->effectivePriceCents() + $extraCents;
            $quantity = max(1, (int) $item['quantity']);

            $priced[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price_cents' => $unit,
                'quantity' => $quantity,
                'modifiers_snapshot' => $snapshot,
                'total_cents' => $unit * $quantity,
            ];
        }

        return $priced;
    }

    /**
     * Percorre os grupos do produto aplicando a regra de preço de cada um.
     *
     * A iteração é pelos grupos do produto, não pelos IDs que o cliente
     * mandou. Isso resolve três coisas de uma vez: uma opção que não pertence
     * a nenhum grupo daquele produto não é encontrada e derruba o pedido;
     * grupo obrigatório sem escolha é barrado; e o teto de max_select é
     * verificado aqui, no servidor, e não só no JavaScript que o cliente pode
     * simplesmente não executar.
     *
     * @param  array<int,int>  $chosenIds
     * @return array{0:int,1:array<int,array<string,mixed>>}
     */
    private function priceGroups(Product $product, array $chosenIds): array
    {
        $remaining = array_fill_keys($chosenIds, true);
        $extraCents = 0;
        $snapshot = [];

        foreach ($product->modifierGroups as $group) {
            $options = $this->options->options($group)->keyBy('id');

            // Escolhas do cliente que pertencem a este grupo, na ordem em que
            // as opções são ofertadas — o snapshot do pedido fica estável e a
            // cozinha lê sempre na mesma sequência.
            $picked = $options
                ->filter(fn (array $option) => isset($remaining[$option['id']]))
                ->values();

            if ($picked->count() < $group->min_select) {
                throw ValidationException::withMessages([
                    'items' => "Escolha as opções obrigatórias de \"{$group->name}\".",
                ]);
            }

            if ($picked->count() > $group->max_select) {
                throw ValidationException::withMessages([
                    'items' => "Você escolheu opções demais em \"{$group->name}\".",
                ]);
            }

            foreach ($picked as $option) {
                unset($remaining[$option['id']]);

                $snapshot[] = [
                    'id' => $option['id'],
                    'name' => $option['name'],
                    'groupName' => $group->name,
                    'priceDeltaCents' => $option['priceCents'],
                ];
            }

            $extraCents += $group->applyPricing($picked->pluck('priceCents')->all());
        }

        /*
         * Sobrou ID que não casou com nenhum grupo deste produto.
         *
         * Pode ser um sabor que saiu do ar entre montar o carrinho e finalizar,
         * ou um ID de outro produto colado no payload para levar um adicional
         * caro por preço de outro. Nos dois casos o pedido não segue: cobrar
         * por algo que não foi validado é justamente o furo que este serviço
         * existe para fechar.
         */
        if ($remaining !== []) {
            throw ValidationException::withMessages([
                'items' => 'Uma das opções escolhidas não está mais disponível.',
            ]);
        }

        return [$extraCents, $snapshot];
    }

    private function deliveryFee(Tenant $tenant): int
    {
        return (int) ($tenant->settings?->delivery_config['fee_cents'] ?? 0);
    }

    private function isPayOnDelivery(string $method): bool
    {
        return in_array($method, ['cash', 'card_on_delivery'], true);
    }

    /**
     * A loja precisa aceitar o modo de recebimento escolhido.
     *
     * O storefront só oferta o que está habilitado, mas a checagem é feita aqui
     * também: o POST é público e nada impede um payload montado à mão pedindo
     * entrega numa loja que só atende no balcão.
     */
    private function assertFulfillmentAllowed(Tenant $tenant, string $fulfillment): void
    {
        $config = $tenant->settings?->delivery_config ?? [];

        $allowed = match ($fulfillment) {
            'delivery' => $tenant->allowsDelivery() && ($config['accepts_delivery'] ?? true),
            'pickup' => (bool) ($config['accepts_pickup'] ?? true),
            'dine_in' => (bool) ($config['accepts_dine_in'] ?? false),
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Esta loja não aceita pedidos nesta modalidade.',
            ]);
        }
    }

    private function assertPaymentMethodAllowed(Tenant $tenant, string $method): void
    {
        if (! $this->isPayOnDelivery($method) && ! $tenant->acceptsOnlinePayment()) {
            throw ValidationException::withMessages([
                'payment_method' => 'Esta loja ainda não aceita pagamento online.',
            ]);
        }
    }
}
