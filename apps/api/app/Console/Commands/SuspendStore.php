<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Suspende uma loja, tirando-a do ar sem apagar nada.
 *
 * Diferente da exclusão: `status = suspended` é reversível por
 * `store:reactivate`, e nenhum dado é tocado — pedidos, produtos e usuários
 * continuam exatamente como estavam. O IdentifyTenant devolve 403 para o
 * storefront, e o Next renderiza a tela neutra de loja indisponível.
 */
class SuspendStore extends Command
{
    protected $signature = 'store:suspend {slug : Slug da loja}';

    protected $description = 'Suspende uma loja: o storefront sai do ar, os dados permanecem';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();

        if (! $tenant) {
            $this->error("Loja \"{$this->argument('slug')}\" não encontrada.");

            return self::FAILURE;
        }

        if ($tenant->isSuspended()) {
            $this->warn("A loja \"{$tenant->slug}\" já está suspensa.");

            return self::SUCCESS;
        }

        $tenant->update(['status' => 'suspended']);

        /*
         * Sem invalidação de cache de propósito: o IdentifyTenant guarda apenas
         * o ID do tenant e relê a linha com `Tenant::find()`, então o `status`
         * vem sempre fresco do banco e a suspensão vale na request seguinte.
         * Um `Cache::forget` aqui não teria efeito algum.
         */

        $this->info("Loja \"{$tenant->slug}\" suspensa. O storefront agora responde 403.");
        $this->line("Para reativar: php artisan store:reactivate {$tenant->slug}");

        return self::SUCCESS;
    }
}
