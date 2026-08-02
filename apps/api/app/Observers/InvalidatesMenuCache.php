<?php

namespace App\Observers;

use App\Tenancy\PendingMenuInvalidations;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer compartilhado por todos os models que compõem o cardápio.
 *
 * Centralizar aqui evita o modo de falha mais comum deste tipo de sistema:
 * o admin edita um preço, o cache não é invalidado, e a loja segue vendendo
 * pelo valor antigo até o TTL expirar.
 *
 * O observer apenas ANOTA o tenant afetado; quem incrementa a versão e dispara
 * o purge é o PendingMenuInvalidations, uma vez por request. A diferença
 * importa: incrementar aqui fazia uma operação em lote virar uma sequência de
 * UPDATEs na mesma linha de `tenants` — a linha que o OrderNumberGenerator
 * precisa travar para numerar cada pedido.
 */
class InvalidatesMenuCache
{
    public function __construct(private PendingMenuInvalidations $pending) {}

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

        $this->pending->push((int) $tenantId);
    }
}
