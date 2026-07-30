<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\MenuService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __invoke(
        Request $request,
        MenuService $menu,
        TenantContext $context,
    ): JsonResponse {
        $tenant = $context->getOrFail();
        $payload = $menu->forTenant($tenant);

        $response = response()->json($payload);

        // Camada de cache HTTP — o item de maior impacto de toda a stack.
        // Com s-maxage=60 na CDN, uma loja com 500 acessos/min gera ~1 request
        // por minuto até o Laravel. stale-while-revalidate mantém a resposta
        // instantânea durante a revalidação em background.
        $response->headers->set(
            'Cache-Control',
            'public, s-maxage=60, max-age=15, stale-while-revalidate=300'
        );

        // ETag permite 304 e economiza banda no mobile, que é a maior parte
        // do tráfego de um cardápio.
        $response->setEtag(md5($tenant->id.':'.$payload['version']));
        $response->isNotModified($request);

        return $response;
    }
}
