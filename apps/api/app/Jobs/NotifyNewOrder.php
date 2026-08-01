<?php

namespace App\Jobs;

use App\Events\OrderReceived;
use App\Models\Order;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa a cozinha sobre um pedido confirmado.
 *
 * Recebe IDs em vez do model: o payload da fila fica pequeno e o estado é
 * sempre relido fresco, evitando processar um snapshot obsoleto.
 *
 * O canal é único: o broadcast via WebSocket (OrderReceived), que o painel
 * escuta e anuncia com som. Sem redundância fora do painel — se o broadcast
 * falhar, o retry do job é o que garante a entrega.
 */
class NotifyNewOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public int $orderId,
        public int $tenantId,
    ) {}

    public function handle(TenantContext $context): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $context->runFor($tenant, function () use ($tenant) {
            $order = Order::find($this->orderId);

            if (! $order) {
                return;
            }

            // Alerta da cozinha: painel do restaurante, em tempo real.
            OrderReceived::dispatch($order->id, $tenant->id, $order->number);
        });
    }
}
