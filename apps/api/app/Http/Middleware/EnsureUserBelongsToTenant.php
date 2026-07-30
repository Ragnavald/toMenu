<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Estar autenticado não significa estar autorizado NESTE tenant.
 *
 * Sem esta checagem, o dono da Pizzaria A poderia autenticar normalmente e
 * então apontar para o subdomínio da Pizzaria B — o token é válido, e o
 * global scope aplicaria o tenant B. Este middleware fecha essa porta.
 */
class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = app(TenantContext::class)->id();

        abort_if($user === null, 401);

        // Staff da plataforma (tenant_id NULL) pode operar em qualquer tenant.
        if ($user->isPlatformStaff()) {
            return $next($request);
        }

        abort_if($user->tenant_id !== $tenantId, 403, 'Acesso negado a esta loja.');

        return $next($request);
    }
}
