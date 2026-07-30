<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Modifier;
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
    public function __construct(private OrderNumberGenerator $numbers) {}

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
        $products = Product::whereIn('id', $productIds)
            ->where('is_available', true)
            ->get()
            ->keyBy('id');

        $modifierIds = collect($items)->pluck('modifier_ids')->flatten()->filter()->unique();
        $modifiers = Modifier::whereIn('id', $modifierIds)
            ->where('is_available', true)
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

            $unit = $product->effectivePriceCents();
            $snapshot = [];

            foreach ($item['modifier_ids'] ?? [] as $modifierId) {
                $modifier = $modifiers->get($modifierId);

                if (! $modifier) {
                    throw ValidationException::withMessages([
                        'items' => 'Uma das opções escolhidas não está mais disponível.',
                    ]);
                }

                $unit += $modifier->price_delta_cents;
                $snapshot[] = [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'priceDeltaCents' => $modifier->price_delta_cents,
                ];
            }

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

    private function deliveryFee(Tenant $tenant): int
    {
        return (int) ($tenant->settings?->delivery_config['fee_cents'] ?? 0);
    }

    private function isPayOnDelivery(string $method): bool
    {
        return in_array($method, ['cash', 'card_on_delivery'], true);
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
