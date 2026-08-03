<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Marca como inadimplente a loja cujo trial venceu sem assinatura.
 *
 * Até aqui o trial de 14 dias vencia e não acontecia nada: a loja seguia com
 * acesso completo, indefinidamente, e ninguém era cobrado.
 *
 * O que este comando faz é apenas sinalizar. A loja continua no ar e o painel
 * passa a mostrar o aviso de pendência — tirar do ar um restaurante no meio do
 * almoço por causa de um vencimento é dano desproporcional, e o lojista pode
 * simplesmente não ter visto o aviso. Suspender segue sendo decisão do staff,
 * por `store:suspend`.
 *
 * Quem tem assinatura ativa nunca chega aqui: o Stripe cobra no fim do trial e
 * o webhook mantém `subscription_status` em `active`.
 */
class ExpireTrials extends Command
{
    protected $signature = 'trials:expire';

    protected $description = 'Marca como past_due as lojas com trial vencido e sem assinatura ativa';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            // Só quem ainda está em trial. Uma loja já `past_due` não precisa
            // ser remarcada, e `suspended` é decisão do staff que este comando
            // não pode desfazer.
            ->where('status', 'trial')
            /*
             * `trialing` conta como em dia: o lojista já cadastrou cartão e o
             * Stripe cobra sozinho no fim do período. Marcá-lo como devedor
             * seria cobrar duas vezes a atenção de quem já resolveu.
             */
            ->where(function ($query) {
                $query->whereNull('subscription_status')
                    ->orWhereNotIn('subscription_status', ['active', 'trialing']);
            })
            ->get();

        foreach ($tenants as $tenant) {
            $tenant->update(['status' => 'past_due']);

            $this->line("Loja \"{$tenant->slug}\" marcada como past_due.");
        }

        $this->info("{$tenants->count()} loja(s) com trial vencido.");

        return self::SUCCESS;
    }
}
