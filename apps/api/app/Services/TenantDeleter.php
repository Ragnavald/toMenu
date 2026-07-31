<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidArgumentException;
use Stripe\StripeClient;

/**
 * Exclusão de uma loja pelo próprio lojista.
 *
 * É soft delete, não purga. Pedidos pagos são documento fiscal e o repasse do
 * Stripe pode ainda estar em trânsito na data da exclusão; apagar a linha do
 * tenant levaria junto orders, payments e order_items, porque todas as FKs são
 * cascadeOnDelete. O `deleted_at` corta o acesso preservando o histórico.
 *
 * O slug NÃO é liberado: `TenantRegistrar::uniqueSlug` consulta com
 * `withTrashed()`. Reaproveitar o subdomínio faria uma loja nova herdar URLs
 * indexadas, links em WhatsApp e cache de CDN de uma loja anterior.
 *
 * Sobre cobrança: a plataforma hoje não cria assinatura no Stripe — não há
 * `customers.create` nem `subscriptions.create` em lugar nenhum, e
 * `tenants.stripe_customer_id` nunca recebe valor. Não existe, portanto,
 * assinatura a cancelar aqui. O que existe é a conta Connect (o lojista
 * recebendo dos clientes dele), tratada em `detachConnectAccount`. Se um dia
 * houver assinatura de fato, o ponto de extensão é `cancelPlatformSubscription`.
 */
class TenantDeleter
{
    /**
     * O StripeClient é resolvido sob demanda, não injetado.
     *
     * O SDK valida a api_key no construtor e lança se ela estiver vazia. Como
     * dependência do construtor, isso derrubava a exclusão inteira com HTTP 500
     * em qualquer ambiente sem STRIPE_SECRET — mesmo para uma loja que nunca
     * conectou o Stripe e não tem chamada alguma a fazer. Resolver aqui mantém
     * a exclusão funcionando quando o Stripe não é necessário.
     */
    private function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }

    /**
     * @param  string|null  $reason  Motivo informado pelo lojista, para suporte.
     * @return array{connect: string, subscription: string}  O que foi feito de fato.
     */
    public function delete(Tenant $tenant, ?string $reason = null): array
    {
        $outcome = [
            'connect' => $this->detachConnectAccount($tenant),
            'subscription' => $this->cancelPlatformSubscription($tenant),
        ];

        DB::transaction(function () use ($tenant, $reason) {
            // Revoga os tokens de todos os usuários da loja: sem isso, um token
            // Sanctum já emitido continua válido até expirar, e o middleware de
            // admin passaria enquanto o cache do tenant não vencesse.
            DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)
                ->whereIn('tokenable_id', $tenant->users()->pluck('id'))
                ->delete();

            $tenant->update([
                'status' => 'suspended',
                'deletion_reason' => $reason,
            ]);

            $tenant->delete();
        });

        // Depois do commit: o cache mapeia slug -> id e tem TTL de 1 hora. Sem
        // invalidar, o storefront seguiria resolvendo o slug durante esse tempo.
        $this->forgetTenantCache($tenant);

        Log::info('Loja excluída pelo lojista.', [
            'tenant_id' => $tenant->id,
            'slug' => $tenant->slug,
            'connect' => $outcome['connect'],
            'subscription' => $outcome['subscription'],
        ]);

        return $outcome;
    }

    /**
     * Desvincula a conta Connect do lojista.
     *
     * A conta NÃO é deletada no Stripe. Uma conta Express pode ter saldo ainda
     * não repassado, chargeback em aberto ou obrigação fiscal do lado do
     * lojista — o dinheiro é dele, e `accounts->delete()` com saldo pendente
     * falha ou o deixa inacessível. A plataforma apenas para de cobrar
     * comissão e esquece o vínculo; o lojista segue dono da conta no Stripe.
     *
     * Falha aqui não aborta a exclusão: o lojista pediu para sair, e deixá-lo
     * preso porque a API do Stripe está fora do ar seria pior. O log permite
     * reconciliação manual.
     */
    private function detachConnectAccount(Tenant $tenant): string
    {
        if (! $tenant->stripe_account_id) {
            return 'none';
        }

        try {
            // Marca a conta no Stripe para que ela seja identificável no
            // dashboard depois que o vínculo local sumir.
            $this->stripe()->accounts->update($tenant->stripe_account_id, [
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'platform_status' => 'detached',
                    'detached_at' => now()->toIso8601String(),
                ],
            ]);

            $tenant->update(['stripe_charges_enabled' => false]);

            return 'detached';
        } catch (ApiErrorException|InvalidArgumentException $e) {
            Log::warning('Falha ao desvincular conta Connect na exclusão da loja.', [
                'tenant_id' => $tenant->id,
                'stripe_account_id' => $tenant->stripe_account_id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Cancela a assinatura da loja NA plataforma.
     *
     * Ponto de extensão, hoje inerte por um motivo concreto: nenhuma assinatura
     * é criada em lugar nenhum do código, então `stripe_customer_id` é sempre
     * NULL e não há id de subscription a cancelar. O guard abaixo não é
     * defensivo — ele é a implementação correta enquanto o registro do
     * customer não existir.
     *
     * Quando a cobrança recorrente for implementada (customer no signup +
     * subscription no plano), o corpo do `if` passa a valer e deve chamar
     * `subscriptions->cancel()` para cada assinatura ativa do customer.
     */
    private function cancelPlatformSubscription(Tenant $tenant): string
    {
        if (! $tenant->stripe_customer_id) {
            // Caminho real hoje: não há cobrança recorrente na plataforma.
            return 'not_applicable';
        }

        try {
            $subscriptions = $this->stripe()->subscriptions->all([
                'customer' => $tenant->stripe_customer_id,
                'status' => 'active',
                'limit' => 100,
            ]);

            foreach ($subscriptions->data as $subscription) {
                $this->stripe->subscriptions->cancel($subscription->id);
            }

            return $subscriptions->data === [] ? 'none' : 'cancelled';
        } catch (ApiErrorException|InvalidArgumentException $e) {
            // Diferente do Connect, aqui a falha significa que o lojista pode
            // continuar sendo cobrado. Loga em nível de erro para alertar.
            Log::error('Falha ao cancelar assinatura na exclusão da loja.', [
                'tenant_id' => $tenant->id,
                'stripe_customer_id' => $tenant->stripe_customer_id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /** Derruba o mapa slug -> id que o IdentifyTenant mantém por 1 hora. */
    private function forgetTenantCache(Tenant $tenant): void
    {
        Cache::forget("tenant-id:slug:{$tenant->slug}");
    }
}
