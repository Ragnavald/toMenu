<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Gera o número sequencial do pedido dentro do tenant.
 *
 * O restaurante espera "Pedido #42", não o id global "#918273". Isso exige um
 * contador por tenant, que é justamente onde a concorrência morde: no pico do
 * almoço, dois pedidos simultâneos leem o mesmo MAX(number) e colidem no índice
 * unique (tenant_id, number).
 *
 * A trava é obtida na linha do tenant — não em MAX(number) — porque a linha
 * existe e pode ser bloqueada, enquanto um agregado sobre linhas ainda não
 * inseridas não impede inserção concorrente (phantom read).
 */
class OrderNumberGenerator
{
    public function next(Tenant $tenant): int
    {
        return DB::transaction(function () use ($tenant) {
            // Serializa a geração de número entre requests do MESMO tenant.
            // Tenants distintos travam linhas distintas e não se afetam.
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();

            $last = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->max('number');

            return (int) $last + 1;
        });
    }
}
