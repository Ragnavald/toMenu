<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta em tempo real para o painel do restaurante.
 *
 * O canal é privado e nomeado por tenant. A autorização vive em routes/channels.php
 * e compara o tenant do usuário — sem isso, qualquer autenticado poderia assinar
 * o canal de outra loja e ver os pedidos dela em tempo real.
 *
 * `ShouldBroadcastNow` e não `ShouldBroadcast`: quem despacha este evento é o
 * NotifyNewOrder, que já é um job de fila. Com o broadcast enfileirado o alerta
 * daria dois saltos — job → fila → job → socket — e o tempo até a cozinha
 * passaria a depender de mais um ciclo de worker. O passo assíncrono já
 * aconteceu; aqui só resta publicar, que é uma chamada HTTP curta ao Reverb.
 */
class OrderReceived implements ShouldBroadcastNow
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
