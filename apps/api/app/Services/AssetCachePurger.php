<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Invalida no cache de borda a URL de uma imagem que deixou de valer.
 *
 * O bucket fica atrás do mesmo CDN do storefront, com TTL longo — é o
 * comportamento desejado para imagem, que quase nunca muda e é o objeto mais
 * pesado da página. O efeito colateral aparece na troca: o objeto antigo segue
 * cacheado na borda por horas depois de ter sido apagado do bucket, e o
 * visitante que já tinha aquela URL continua recebendo a imagem anterior.
 *
 * Por que isso importa mesmo com nome versionado: o `put()` gera nome novo a
 * cada upload (`{tenantId}-logo-{timestamp}.ext`), então a URL NOVA nunca sai
 * do cache errado. Quem precisa do purge é a URL ANTIGA — ela continua viva na
 * borda depois de o arquivo sumir do bucket, e é ela que está gravada em
 * qualquer HTML, link compartilhado ou preview de rede social já emitido. Sem
 * o purge, o objeto some do storage mas a borda segue servindo uma cópia que
 * não tem mais origem, e quando o TTL enfim expira o visitante toma 404.
 *
 * O purge é por URL exata, nunca por hostname: o domínio de assets é
 * compartilhado por todas as lojas, e invalidar o host inteiro a cada troca de
 * logo derrubaria o cache de imagem da plataforma toda — trocaria um objeto
 * obsoleto por um pico de origem em todas as lojas ao mesmo tempo.
 *
 * Falha não propaga. Um purge perdido é degradação (a borda volta a depender do
 * TTL), e o lojista não pode perder o upload que acabou de fazer porque a API
 * do Cloudflare estava fora do ar.
 */
class AssetCachePurger
{
    /**
     * @param  list<string|null>  $urls  URLs de objetos que saíram do bucket.
     */
    public function purge(array $urls): void
    {
        $zone = config('tenancy.purge.cloudflare_zone_id');
        $token = config('tenancy.purge.cloudflare_api_token');

        // Sem credencial o recurso fica inerte, como as demais camadas de
        // purge: em desenvolvimento e nos testes não há borda para avisar.
        if (! $zone || ! $token) {
            return;
        }

        // Só URLs absolutas interessam. Em ambiente sem disco remoto o `put()`
        // devolve caminho do disco público, que não passa por CDN nenhum.
        $files = collect($urls)
            ->filter(fn (?string $url) => is_string($url) && str_starts_with($url, 'http'))
            ->unique()
            ->values()
            ->all();

        if ($files === []) {
            return;
        }

        try {
            $response = Http::timeout((int) config('tenancy.purge.timeout', 5))
                ->withToken($token)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", [
                    'files' => $files,
                ]);

            if ($response->failed()) {
                Log::warning('Purge de imagem no Cloudflare falhou.', [
                    'files' => $files,
                    'status' => $response->status(),
                    'body' => $response->json('errors'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Cloudflare inacessível para purge de imagem.', [
                'files' => $files,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
