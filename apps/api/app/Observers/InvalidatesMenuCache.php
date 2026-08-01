<?php

namespace App\Observers;

use App\Jobs\PurgeMenuCache;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer compartilhado por todos os models que compõem o cardápio.
 *
 * Centralizar aqui evita o modo de falha mais comum deste tipo de sistema:
 * o admin edita um preço, o cache não é invalidado, e a loja segue vendendo
 * pelo valor antigo até o TTL expirar.
 */
class InvalidatesMenuCache
{
    public function saved(Model $model): void
    {
        $this->bump($model);
    }

    public function deleted(Model $model): void
    {
        $this->bump($model);
    }

    private function bump(Model $model): void
    {
        $tenantId = $model->tenant_id ?? null;

        if ($tenantId === null) {
            return;
        }

        // withoutGlobalScopes: Tenant não é tenant-scoped, mas o contexto pode
        // estar ativo — e um increment é mais barato que carregar a relação.
        Tenant::withoutGlobalScopes()
            ->whereKey($tenantId)
            ->increment('menu_version');

        // O bump acima só resolve o cache do Redis. Next e Cloudflare guardam
        // suas próprias cópias e precisam ser avisados, senão o lojista salva
        // e não vê a mudança no site.
        //
        // afterCommit é essencial: despachado dentro da transação, o worker
        // poderia rodar antes do commit, reconstruir o cardápio lendo o estado
        // antigo e recachear justamente o dado obsoleto que viemos invalidar.
        PurgeMenuCache::dispatch($tenantId)->afterCommit();
    }
}
