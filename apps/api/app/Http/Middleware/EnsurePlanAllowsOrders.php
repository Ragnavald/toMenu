<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fecha a operação de pedidos para lojas no plano somente-cardápio.
 *
 * A interface já esconde carrinho e checkout nesse plano, mas esconder não é
 * impedir: o endpoint é público e a rota é conhecida por quem já visitou uma
 * loja Pro. Sem esta barreira, um POST direto criaria pedidos que ninguém no
 * painel jamais veria — a loja não tem tela de operação — e o cliente final
 * ficaria esperando uma comida que a cozinha nunca soube que foi pedida.
 *
 * 403 e não 404: o recurso existe e a loja existe; o que falta é contratação.
 */
class EnsurePlanAllowsOrders
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->getOrFail();

        abort_if(
            ! $tenant->allowsOrders(),
            403,
            'Esta loja não recebe pedidos pelo site.',
        );

        return $next($request);
    }
}
