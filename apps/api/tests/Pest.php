<?php

use App\Models\Tenant;
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

/** Limpa o contexto — usado para simular jobs e rotas centrais. */
function forgetTenant(): void
{
    app(TenantContext::class)->forget();
}
