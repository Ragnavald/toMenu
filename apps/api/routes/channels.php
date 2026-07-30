<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Sem esta autorização, qualquer usuário autenticado poderia assinar o canal de
 * outra loja e acompanhar os pedidos dela em tempo real. O isolamento de
 * WebSocket é tão importante quanto o de HTTP e é frequentemente esquecido.
 */
Broadcast::channel('tenant.{tenantId}.orders', function (User $user, int $tenantId) {
    return $user->tenant_id === $tenantId || $user->isPlatformStaff();
});
