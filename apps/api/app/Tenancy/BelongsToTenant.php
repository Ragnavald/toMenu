<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Camada 2 do isolamento: escopo automático em toda query do model.
 *
 * Aplica-se apenas quando há tenant no contexto. Sem isso, seeders, jobs
 * centrais e migrations não conseguiriam operar — e o RLS (camada 3) continua
 * protegendo o acesso via HTTP mesmo que este escopo seja removido por engano.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->check()) {
                $query->where(
                    $query->getModel()->qualifyColumn('tenant_id'),
                    $context->id()
                );
            }
        });

        // Preenche tenant_id automaticamente: o código de domínio nunca deveria
        // precisar informá-lo, e esquecer disso é a origem clássica de vazamento.
        static::creating(function ($model) {
            if ($model->tenant_id === null) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Resolve o route model binding restrito ao tenant do contexto.
     *
     * O binding do Laravel roda em `substituteBindings`, que pode ser avaliado
     * antes de o contexto de tenant existir — nesse caso o global scope não
     * tem o que filtrar e o model de outra loja é resolvido normalmente. A
     * requisição prosseguia até o update, onde o RLS bloqueava a escrita no
     * banco mas a API ainda respondia 200 com o objeto alterado em memória:
     * nada era corrompido, porém o cliente recebia uma confirmação falsa.
     *
     * Filtrar explicitamente aqui transforma isso no 404 correto.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $tenantId = app(TenantContext::class)->id();

        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->when($tenantId !== null, fn ($query) => $query->where(
                $this->qualifyColumn('tenant_id'),
                $tenantId,
            ))
            ->first();
    }
}
