<?php

use App\Models\Tenant;
use App\Tenancy\PendingMenuInvalidations;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/*
 * Redis não é limpo entre testes como o store `array` era.
 *
 * Sem o flush, o cache negativo da resolução de tenant ("slug não existe" → 0)
 * sobrevive de um teste para o outro e o tenant recém-criado pelo teste
 * seguinte é rejeitado com 404. Limpar aqui mantém os testes independentes.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Cache::flush())
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
