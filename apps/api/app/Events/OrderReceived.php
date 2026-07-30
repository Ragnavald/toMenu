<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta em tempo real para o painel do restaurante.
 *
 * O canal é privado e nomeado por tenant. A autorização vive em routes/channels.php
 * e compara o tenant do usuário — sem isso, qualquer autenticado poderia assinar
 * o canal de outra loja e ver os pedidos dela em tempo real.
 */
class OrderReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $orderId,
        public int $tenantId,
        public int $orderNumber,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.orders")];
    }

    public function broadcastAs(): string
    {
        return 'order.received';
    }
}
