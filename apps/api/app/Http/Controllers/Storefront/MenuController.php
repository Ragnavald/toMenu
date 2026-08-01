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
        // Uma loja com 500 acessos/min continua gerando pouquíssimos requests
        // até o Laravel, e stale-while-revalidate mantém a resposta instantânea
        // durante a revalidação em background.
        //
        // O TTL é curto e serve de rede de proteção: quem invalida de verdade
        // é o PurgeMenuCache, no instante da edição. max-age (browser) fica em
        // 0 porque não há como purgar o cache do visitante — era ele que fazia
        // o lojista recarregar a própria loja e continuar vendo o cardápio
        // antigo mesmo depois de a origem já ter atualizado.
        $ttl = (int) config('tenancy.cache.cdn_ttl', 30);

        $response->headers->set(
            'Cache-Control',
            "public, s-maxage={$ttl}, max-age=0, stale-while-revalidate=300"
        );

        // ETag permite 304 e economiza banda no mobile, que é a maior parte
        // do tráfego de um cardápio.
        $response->setEtag(md5($tenant->id.':'.$payload['version']));
        $response->isNotModified($request);

        return $response;
    }
}
