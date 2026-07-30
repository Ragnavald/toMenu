<?php

namespace App\Jobs;

use App\Events\OrderReceived;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa estabelecimento e cliente sobre um pedido confirmado.
 *
 * Recebe IDs em vez do model: o payload da fila fica pequeno e o estado é
 * sempre relido fresco, evitando processar um snapshot obsoleto.
 *
 * Importante: WhatsApp isoladamente não é canal confiável para a cozinha —
 * em horário de pico ninguém olha o celular. O broadcast via WebSocket
 * (OrderReceived) é o alerta primário, com som contínuo no painel; o WhatsApp
 * é redundância.
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

    public function handle(TenantContext $context, WhatsAppService $whatsapp): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $context->runFor($tenant, function () use ($tenant, $whatsapp) {
            $order = Order::with('items', 'customer')->find($this->orderId);

            if (! $order) {
                return;
            }

            // Alerta primário: painel do restaurante, em tempo real.
            OrderReceived::dispatch($order->id, $tenant->id, $order->number);

            $whatsapp->notifyMerchant($tenant, $order);
            $whatsapp->notifyCustomer($tenant, $order);
        });
    }
}
