<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\NotifyNewOrder;
use App\Services\OrderService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

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
            'totalCents' => $order->total_cents,
            'requiresPayment' => ! $order->isPayOnDelivery(),
        ], 201);
    }
}
