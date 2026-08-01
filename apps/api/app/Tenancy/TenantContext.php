<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Detentor do tenant ativo na request corrente.
 *
 * Registrado como singleton. Deliberadamente NÃO usa propriedade estática:
 * sob Laravel Octane o worker sobrevive entre requests, e estado estático
 * vaza de um tenant para o próximo — o pior bug possível nesta arquitetura.
 * O singleton é destruído junto com o container a cada request.
 *
 * O reset explícito ainda é feito em OctaneTenancyProvider por garantia.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->applyToDatabase($tenant->getKey());
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function getOrFail(): Tenant
    {
        return $this->tenant ?? throw new \RuntimeException(
            'Nenhum tenant no contexto. A rota passou pelo middleware identify.tenant?'
        );
    }

    /**
     * Executa um callback dentro do contexto de outro tenant e restaura depois.
     * Usado por jobs de fila e handlers de webhook, que chegam sem contexto HTTP.
     */
    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous ? $this->set($previous) : $this->forget();
        }
    }

    /**
     * Executa um callback deliberadamente FORA de qualquer tenant, restaurando
     * o contexto anterior depois.
     *
     * O inverso do runFor. Serve às rotas da plataforma, que operam sobre
     * lojas das quais o staff não é membro: com um tenant no contexto, o global
     * scope e as policies de RLS recortariam as consultas ao tenant errado e
     * devolveriam contagens zeradas em vez de erro — falha silenciosa, que é o
     * modo pior de errar aqui.
     *
     * O `finally` é o que impede um vazamento: sem ele, uma exceção no meio da
     * purga deixaria a request seguindo sem tenant, e o global scope pararia de
     * filtrar em tudo que viesse depois.
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $previous = $this->tenant;

        $this->forget();

        try {
            return $callback();
        } finally {
            $previous ? $this->set($previous) : $this->forget();
        }
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->applyToDatabase(null);
    }

    /**
     * Propaga o tenant para a sessão do Postgres, alimentando as policies de RLS.
     *
     * set_config com is_local=false (terceiro parâmetro) é intencional: SET LOCAL
     * só vale até o fim da transação, e a maior parte das leituras roda fora de
     * qualquer transação explícita. Como cada request usa uma conexão do pool e o
     * valor é reescrito no início da próxima, o escopo continua correto.
     */
    private function applyToDatabase(?int $tenantId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT set_config(?, ?, false)', [
            'app.tenant_id',
            $tenantId === null ? '' : (string) $tenantId,
        ]);
    }
}
