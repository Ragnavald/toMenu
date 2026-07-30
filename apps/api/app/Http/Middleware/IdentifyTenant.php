<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Camada 1 do isolamento: resolve o tenant a partir da requisição.
 *
 * Ordem de resolução (mais específica primeiro):
 *   1. custom_domain  — cardapio.restaurante.com.br
 *   2. subdomínio     — pizzaria.tomenu.app
 *   3. path param     — tomenu.app/pizzaria  (fallback)
 *
 * O header X-Tenant só é aceito quando explicitamente habilitado, para uso do
 * frontend em desenvolvimento (onde não há wildcard DNS). Em produção ele é
 * ignorado: aceitar tenant vindo do cliente permitiria trocar de tenant à
 * vontade — a falha de isolamento mais grave possível.
 */
class IdentifyTenant
{
    /** Subdomínios da própria plataforma, nunca são tenants. */
    private const RESERVED = ['www', 'app', 'api', 'admin', 'central', 'mail', 'static'];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

        abort_if($tenant === null, 404, 'Loja não encontrada.');
        abort_if($tenant->isSuspended(), 403, 'Esta loja está temporariamente indisponível.');

        app(TenantContext::class)->set($tenant);

        $response = $next($request);

        // Ajuda debugging e cache keys em CDN.
        $response->headers->set('X-Tenant', $tenant->slug);

        return $response;
    }

    private function resolve(Request $request): ?Tenant
    {
        $identifier = $this->identifierFrom($request);

        if ($identifier === null) {
            return null;
        }

        [$type, $value] = $identifier;

        /*
         * Cacheia apenas o ID, não o model.
         *
         * Serializar um model Eloquent no cache é frágil: a desserialização
         * depende da classe estar carregada e do formato dos atributos, e
         * qualquer divergência devolve __PHP_Incomplete_Class em vez do objeto.
         * Guardar o inteiro elimina a classe inteira de problema — o custo é
         * um SELECT por primary key, que é trivial.
         *
         * O valor 0 marca "não existe" para que hosts inválidos não consultem
         * o banco a cada request (cache negativo contra enumeração de slugs).
         */
        $tenantId = Cache::remember(
            "tenant-id:{$type}:{$value}",
            now()->addHour(),
            function () use ($type, $value) {
                $tenant = $type === 'domain'
                    ? Tenant::where('custom_domain', $value)->first()
                    : Tenant::where('slug', $value)->first();

                return $tenant?->getKey() ?? 0;
            }
        );

        return $tenantId === 0 ? null : Tenant::find($tenantId);
    }

    /** @return array{0:string,1:string}|null */
    private function identifierFrom(Request $request): ?array
    {
        $host = strtolower($request->getHost());
        $root = strtolower((string) config('tenancy.root_domain'));

        if ($root !== '' && str_ends_with($host, ".{$root}")) {
            $sub = substr($host, 0, -(strlen($root) + 1));

            if ($sub !== '' && ! in_array($sub, self::RESERVED, true) && ! str_contains($sub, '.')) {
                return ['slug', $sub];
            }
        } elseif ($root !== '' && $host !== $root && $host !== 'localhost') {
            return ['domain', $host];
        }

        if ($slug = $request->route('tenant')) {
            return ['slug', strtolower((string) $slug)];
        }

        if (config('tenancy.trust_header') && ($slug = $request->header('X-Tenant'))) {
            return ['slug', strtolower($slug)];
        }

        return null;
    }
}
