<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Invalida o cardápio nas camadas de cache que ficam fora do Laravel.
 *
 * O cache do Redis já cai na hora, pelo bump de menu_version. O que sobrava
 * eram duas camadas HTTP governadas só por TTL — o ISR do Next e o s-maxage no
 * Cloudflare — que não estão sincronizadas entre si e portanto se somavam: uma
 * edição podia levar até ~2min para chegar ao cliente. Avisar as duas no
 * instante da edição derruba isso para segundos.
 *
 * As duas chamadas são independentes de propósito. Purgar só o Cloudflare
 * deixaria o Next servindo HTML antigo; purgar só o Next deixaria a borda
 * servindo a cópia dela. Uma falhar não impede a outra de rodar — e nenhuma
 * falha propaga exceção, porque um purge perdido é degradação (volta-se ao
 * TTL), não motivo para derrubar o salvamento que o lojista acabou de fazer.
 */
class MenuCachePurger
{
    public function purge(Tenant $tenant): void
    {
        $this->purgeStorefront($tenant);
        $this->purgeCloudflare($tenant);
    }

    /**
     * Expira a tag do cardápio no Data Cache do Next.
     *
     * Vai pela rede interna do compose: a chamada não precisa sair para a
     * internet e voltar, e o storefront não expõe esta rota publicamente de
     * forma útil sem o segredo.
     */
    private function purgeStorefront(Tenant $tenant): void
    {
        $base = config('tenancy.purge.storefront_url');
        $secret = config('tenancy.purge.revalidate_secret');

        // Sem configuração o recurso fica inerte: em desenvolvimento e nos
        // testes não há storefront para avisar, e o TTL segue correto.
        if (! $base || ! $secret) {
            return;
        }

        try {
            $response = Http::timeout((int) config('tenancy.purge.timeout', 5))
                ->withHeaders(['x-revalidate-secret' => $secret])
                ->post(rtrim($base, '/').'/api/revalidate?tenant='.urlencode($tenant->slug));

            if ($response->failed()) {
                Log::warning('Revalidação do storefront falhou.', [
                    'tenant' => $tenant->slug,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Revalidação do storefront inacessível.', [
                'tenant' => $tenant->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Purga o HTML da loja no cache de borda.
     *
     * O purge é por hostname, não por URL: o cardápio pode estar cacheado em
     * mais de um caminho (`/` e `/{slug}`, o fallback por path) e listar URLs
     * uma a uma deixaria alguma para trás. `purge_everything` seria pior — a
     * zona inteira é compartilhada por todas as lojas, e uma edição numa loja
     * esvaziaria o cache de todas as outras.
     */
    private function purgeCloudflare(Tenant $tenant): void
    {
        $zone = config('tenancy.purge.cloudflare_zone_id');
        $token = config('tenancy.purge.cloudflare_api_token');

        if (! $zone || ! $token) {
            return;
        }

        $host = parse_url($tenant->storefrontUrl(), PHP_URL_HOST);

        if (! $host) {
            return;
        }

        try {
            $response = Http::timeout((int) config('tenancy.purge.timeout', 5))
                ->withToken($token)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", [
                    'hosts' => [$host],
                ]);

            if ($response->failed()) {
                Log::warning('Purge do Cloudflare falhou.', [
                    'tenant' => $tenant->slug,
                    'status' => $response->status(),
                    'body' => $response->json('errors'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Cloudflare inacessível para purge.', [
                'tenant' => $tenant->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
