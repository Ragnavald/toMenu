<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\NotifyNewOrder;
use App\Models\Order;
use App\Services\OrderService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(
        StoreOrderRequest $request,
        OrderService $orders,
        TenantContext $context,
    ): JsonResponse {
        $tenant = $context->getOrFail();
        $order = $orders->create($tenant, $request->validated());

        // Notificação só aqui quando o pagamento é na entrega — não há gateway
        // a confirmar. Para pagamento online o disparo acontece no webhook do
        // Stripe: avisar a cozinha antes da confirmação faria o restaurante
        // preparar pedidos que podem nunca ser pagos.
        if ($order->isPayOnDelivery()) {
            NotifyNewOrder::dispatch($order->id, $tenant->id);
        }

        return response()->json([
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'fulfillment' => $order->fulfillment,
            'totalCents' => $order->total_cents,
            'requiresPayment' => ! $order->isPayOnDelivery(),
        ], 201);
    }

    /*
     * O pedido é resolvido pelo NOME do parâmetro, não por type-hint.
     *
     * Esta ação atende duas rotas: `/api/orders/{order}` e
     * `/api/{tenant}/orders/{order}`. Com `Order $order` na assinatura o
     * Laravel injeta o primeiro parâmetro da rota — que na segunda é o slug
     * do tenant, uma string — e o binding implícito estoura TypeError.
     * Lendo pelo nome, a mesma assinatura serve às duas.
     */
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        // O global scope do tenant continua valendo: um id de outra loja não
        // é encontrado aqui. A conferência de tenant_id logo abaixo é a
        // segunda barreira, não a única.
        $order = Order::whereKey($request->route('order'))->first();

        abort_if($order === null || $order->tenant_id !== $tenant->id, 404);

        $order->load(['items:id,order_id,product_name,quantity,unit_price_cents,total_cents']);

        return response()->json([
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'fulfillment' => $order->fulfillment,
            'paymentMethod' => $order->payment_method,
            'paymentStatus' => $order->payment_status,
            'subtotalCents' => $order->subtotal_cents,
            'deliveryFeeCents' => $order->delivery_fee_cents,
            'totalCents' => $order->total_cents,
            'placedAt' => $order->placed_at,
            'confirmedAt' => $order->confirmed_at,
            'deliveredAt' => $order->delivered_at,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'unitPriceCents' => $item->unit_price_cents,
                'totalCents' => $item->total_cents,
            ]),
        ]);
    }
}
