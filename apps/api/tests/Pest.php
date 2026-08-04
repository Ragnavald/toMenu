<?php

use App\Models\Tenant;
use App\Tenancy\PendingMenuInvalidations;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
 * Redis não é limpo entre testes como o store `array` era.
 *
 * Sem o flush, o cache negativo da resolução de tenant ("slug não existe" → 0)
 * sobrevive de um teste para o outro e o tenant recém-criado pelo teste
 * seguinte é rejeitado com 404. Limpar aqui mantém os testes independentes.
 *
 * Os LOCKS precisam de uma limpeza própria, e não são detalhe: `Cache::flush()`
 * limpa a conexão `cache` (REDIS_CACHE_DB), enquanto `Cache::lock()` grava na
 * conexão `lock_connection`, que por padrão é a `default` — outro banco Redis,
 * que o flush não alcança.
 *
 * Quem depende disso é todo job `ShouldBeUnique`. O RefreshStreetMap trava por
 * `uniqueFor = 120` segundos usando o tenant_id como chave, e o tenant_id é
 * quase sempre 1 num banco recém-migrado: o lock deixado por uma execução da
 * suíte fazia a execução seguinte descartar o dispatch em silêncio, e o teste
 * do redesenho falhava sem nada ter mudado no código. O TTL expirando sozinho
 * é o que dava a esse erro a aparência de teste instável.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Cache::flush();

        if (config('cache.default') === 'redis') {
            Redis::connection(
                config('cache.stores.redis.lock_connection', 'default'),
            )->flushdb();
        }
    })
    ->in('Feature');

/** Ativa o contexto de tenant, como faria o middleware em uma request real. */
function actingAsTenant(Tenant $tenant): Tenant
{
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/**
 * Limpa o contexto — usado para simular jobs e rotas centrais.
 *
 * Drena também as invalidações de cardápio pendentes. Em produção quem faz
 * isso é o `terminating` da request que gravou; num teste não há troca de
 * request entre a escrita e a leitura seguinte, então sem este flush o
 * cardápio continuaria servido na versão anterior e o teste mediria uma
 * defasagem que o sistema real não tem.
 */
function forgetTenant(): void
{
    flushMenuInvalidations();
    app(TenantContext::class)->forget();
}

/**
 * Aplica os bumps de `menu_version` acumulados, como o fim da request faria.
 *
 * Exposto à parte porque nem todo teste que escreve no cardápio chama
 * `forgetTenant` em seguida.
 */
function flushMenuInvalidations(): void
{
    app(PendingMenuInvalidations::class)->flush();
}
