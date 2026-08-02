<?php

namespace App\Tenancy;

use App\Jobs\PurgeMenuCache;
use App\Models\Tenant;

/**
 * Acumula os tenants cujo cardápio ficou obsoleto e invalida uma vez só.
 *
 * Antes disto o observer incrementava `menu_version` a cada model salvo. Uma
 * operação em lote — reordenar o cardápio arrastando, salvar um produto com
 * seus grupos e modificadores — virava uma sequência de UPDATEs na MESMA linha
 * de `tenants`, todos dentro da mesma transação. Reordenar 30 categorias fazia
 * a versão saltar de 1 para 31.
 *
 * O custo não era o UPDATE em si, era o lock. Essa é a mesma linha que o
 * OrderNumberGenerator trava com `lockForUpdate` para numerar o pedido, então
 * um lojista reorganizando o cardápio no horário de pico segurava a linha até
 * o commit e bloqueava a criação de pedidos da própria loja.
 *
 * Registrado como singleton e drenado no fim da request (ou da transação, o
 * que vier primeiro — ver `flush`). Estado por request, nunca estático: sob
 * Octane o worker sobrevive entre requests e um array estático vazaria os
 * tenants de uma request para a seguinte.
 */
class PendingMenuInvalidations
{
    /** @var array<int,true> Usado como set: a chave é o tenant_id. */
    private array $tenantIds = [];

    /**
     * Marca o cardápio de um tenant como obsoleto, sem tocar no banco ainda.
     *
     * Chamar várias vezes para o mesmo tenant é idempotente — é exatamente o
     * caso que este coletor existe para colapsar.
     */
    public function push(int $tenantId): void
    {
        $this->tenantIds[$tenantId] = true;
    }

    public function pending(): array
    {
        return array_keys($this->tenantIds);
    }

    /**
     * Aplica um único bump por tenant e dispara o purge externo.
     *
     * A lista é esvaziada ANTES do trabalho: se o increment falhar, o flush
     * seguinte não repete um tenant já processado, e mais importante, um
     * observer disparado durante o próprio flush não realimenta a lista e cria
     * recursão. `Tenant` não está entre os MENU_MODELS observados hoje, mas
     * isso é uma linha de configuração de distância.
     *
     * `withoutGlobalScopes` porque este código roda tanto dentro do contexto de
     * um tenant quanto fora dele (jobs, comandos de console, staff da
     * plataforma) e o alvo é sempre um id explícito.
     */
    public function flush(): void
    {
        $tenantIds = $this->pending();
        $this->tenantIds = [];

        if ($tenantIds === []) {
            return;
        }

        // Um UPDATE para todos os tenants da leva, em vez de um por tenant.
        // Na prática a lista quase sempre tem um elemento só; o caso de vários
        // aparece em comandos de console que varrem lojas.
        Tenant::withoutGlobalScopes()
            ->whereIn('id', $tenantIds)
            ->increment('menu_version');

        foreach ($tenantIds as $tenantId) {
            // afterCommit mantido: despachado dentro da transação, o worker
            // poderia rodar antes do commit, reconstruir o cardápio lendo o
            // estado antigo e recachear justamente o dado que viemos invalidar.
            PurgeMenuCache::dispatch($tenantId)->afterCommit();
        }
    }
}
