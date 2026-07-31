<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Reativa uma loja suspensa, com todo o histórico intacto.
 *
 * A suspensão não apaga nada, então reativar é só devolver o status: pedidos,
 * produtos, tema e usuários voltam exatamente como estavam.
 */
class ReactivateStore extends Command
{
    protected $signature = 'store:reactivate {slug : Slug da loja}';

    protected $description = 'Reativa uma loja suspensa, preservando todo o histórico';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();

        if (! $tenant) {
            $this->error("Loja \"{$this->argument('slug')}\" não encontrada.");

            return self::FAILURE;
        }

        if (! $tenant->isSuspended()) {
            $this->warn("A loja \"{$tenant->slug}\" não está suspensa (status: {$tenant->status}).");

            return self::SUCCESS;
        }

        $tenant->update(['status' => 'active']);

        // Ver SuspendStore: o cache do IdentifyTenant guarda só o ID e a linha
        // é relida a cada request, então não há nada a invalidar aqui.

        $this->info("Loja \"{$tenant->slug}\" reativada.");
        $this->line("Storefront: {$tenant->storefrontUrl()}");
        $this->line(sprintf(
            'Histórico preservado: %d pedido(s), %d produto(s).',
            $tenant->orders()->count(),
            $tenant->products()->count(),
        ));

        return self::SUCCESS;
    }
}
