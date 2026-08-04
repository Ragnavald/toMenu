<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\StripeMode;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Cria o cliente no Stripe antes que o lojista clique em assinar.
 *
 * O `ensureCustomer` do SubscriptionService é uma chamada de rede que, no
 * caminho síncrono, acontece exatamente entre o clique e o redirect — somada à
 * criação da sessão e ao carregamento da página do Stripe, que é em outro
 * domínio. Adiantá-la para quando a tela de assinatura abre tira um round-trip
 * inteiro da espera que o lojista percebe.
 *
 * É oportunista, não obrigatório: se falhar ou não rodar a tempo, o checkout
 * cria o cliente como antes. Por isso não relança erro — um Stripe fora do ar
 * aqui não pode encher a fila de retentativas de um trabalho que o próprio
 * checkout refaz.
 *
 * Recebe o id, não o model — payload menor e estado sempre relido fresco, o que
 * importa aqui porque outra requisição pode ter criado o cliente nesse meio
 * tempo.
 */
class EnsureStripeCustomer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var int[] */
    public array $backoff = [10, 60];

    public function __construct(public int $tenantId) {}

    /**
     * Abrir a tela de assinatura duas vezes (ou um F5) não pode virar dois
     * clientes no Stripe: o histórico de cobrança da loja ficaria partido entre
     * eles, e o portal mostraria só uma fatia das faturas.
     */
    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public int $uniqueFor = 120;

    public function handle(SubscriptionService $subscriptions, StripeMode $mode): void
    {
        if (! $mode->isConfigured()) {
            return;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        /*
         * Já tem cliente NO MODO ATIVO: nada a adiantar. É o caso comum a
         * partir da segunda visita à tela.
         *
         * A checagem de modo faz este job assumir também a recriação depois de
         * uma troca de `STRIPE_MODE`: sem ela, um customer do ambiente anterior
         * satisfaria a condição, o job desistiria, e o conserto só aconteceria
         * no clique em assinar — de novo na janela de espera que este job
         * existe justamente para eliminar.
         */
        if (! $tenant
            || ($tenant->stripe_customer_id && $tenant->stripeLinkageMatchesMode($mode->current()))) {
            return;
        }

        try {
            $subscriptions->ensureCustomer($tenant);
        } catch (ApiErrorException|\RuntimeException $e) {
            // Só registra. O checkout tenta de novo por conta própria, então
            // falhar aqui não deixa o lojista sem assinar.
            Log::warning('Assinatura: não foi possível adiantar o cliente no Stripe.', [
                'tenant_id' => $this->tenantId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
