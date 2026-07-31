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
 *   1. subdomínio     — pizzaria.tomenu.app
 *   2. path param     — tomenu.app/pizzaria  (fallback)
 *
 * Domínio próprio não é suportado por decisão de plataforma: o TLS vem do
 * certificado wildcard `*.dominio` do Cloudflare, que cobre exatamente um nível
 * de subdomínio. Um host fora dele não teria certificado válido, então resolver
 * o tenant por ele só produziria erro de TLS no navegador.
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
            "tenant-id:slug:{$identifier}",
            now()->addHour(),
            fn () => Tenant::where('slug', $identifier)->first()?->getKey() ?? 0
        );

        return $tenantId === 0 ? null : Tenant::find($tenantId);
    }

    /** Slug do tenant indicado pela requisição, ou null se nenhum. */
    private function identifierFrom(Request $request): ?string
    {
        $host = strtolower($request->getHost());
        $root = strtolower((string) config('tenancy.root_domain'));

        if ($root !== '' && str_ends_with($host, ".{$root}")) {
            $sub = substr($host, 0, -(strlen($root) + 1));

            // str_contains('.'): "loja.staging.tomenu.app" está fora do wildcard
            // `*.tomenu.app` — um nível só. Tratar como slug daria 200 num host
            // cujo certificado o navegador já teria rejeitado.
            if ($sub !== '' && ! in_array($sub, self::RESERVED, true) && ! str_contains($sub, '.')) {
                return $sub;
            }
        }

        if ($slug = $request->route('tenant')) {
            return strtolower((string) $slug);
        }

        if (config('tenancy.trust_header') && ($slug = $request->header('X-Tenant'))) {
            return strtolower($slug);
        }

        /*
         * Último recurso: o tenant do próprio usuário autenticado.
         *
         * O painel é servido de `app.{dominio}` e chama a API na mesma origem,
         * justamente para dispensar CORS. Só que `app` é subdomínio reservado,
         * a rota de admin não tem `{tenant}` no path, e o X-Tenant é ignorado em
         * produção (`trust_header=false`) — nenhum dos três caminhos acima
         * resolve, e toda chamada a /api/admin/* respondia 404 "Loja não
         * encontrada". O painel inteiro ficava inacessível em produção.
         *
         * Este caminho não enfraquece o isolamento, ao contrário dos outros: o
         * slug não vem da request, vem da coluna `tenant_id` do usuário já
         * autenticado pelo Sanctum. Um usuário só alcança a própria loja, e o
         * EnsureUserBelongsToTenant confere isso de novo logo em seguida.
         *
         * Staff da plataforma (tenant_id nulo) não resolve nada aqui e continua
         * dependendo do host ou do path — para eles o vínculo não existe.
         *
         * Restrito às rotas de admin de propósito. Aplicado a toda rota, o
         * cardápio público passaria a resolver a loja do usuário logado quando
         * o host não identificasse nenhuma — uma rota anônima mudando de
         * resposta por existir sessão. `AdminSameOriginTest` cobre esse caso.
         */
        if (! $request->routeIs('admin.*') && ! $request->is('api/admin/*')) {
            return null;
        }

        $user = $request->user();

        if ($user && $user->tenant_id !== null) {
            return $user->tenant?->slug;
        }

        return null;
    }
}
