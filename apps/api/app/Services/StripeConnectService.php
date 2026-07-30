<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Stripe\StripeClient;

/**
 * Stripe Connect (Express) com destination charges.
 *
 * Modelo escolhido em vez de guardar chaves de API de cada tenant: armazenar
 * secret keys de terceiros cria passivo de segurança e de PCI que a plataforma
 * não precisa assumir. Com Connect, o tenant autoriza via onboarding hospedado
 * pelo Stripe e a plataforma nunca vê credencial alguma.
 *
 * Fluxo do dinheiro: o cliente paga -> o valor vai para a conta do tenant ->
 * a comissão da plataforma é retida via application_fee_amount.
 */
class StripeConnectService
{
    public function __construct(private StripeClient $stripe) {}

    /** Cria a conta Connect e devolve o link de onboarding hospedado. */
    public function startOnboarding(Tenant $tenant, string $returnUrl, string $refreshUrl): string
    {
        if (! $tenant->stripe_account_id) {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'country' => 'BR',
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'company',
                'metadata' => ['tenant_id' => (string) $tenant->id],
            ]);

            $tenant->update(['stripe_account_id' => $account->id]);
        }

        $link = $this->stripe->accountLinks->create([
            'account' => $tenant->stripe_account_id,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    /**
     * PaymentIntent com destino na conta do tenant.
     *
     * O metadata é o que permite ao webhook, que chega sem contexto, saber a
     * qual tenant e pedido o evento pertence. Sem ele o evento é órfão.
     *
     * A idempotency key evita cobrança dupla se o cliente reenviar o checkout.
     */
    public function createPaymentIntent(Tenant $tenant, Order $order): array
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => $order->total_cents,
            'currency' => 'brl',
            'application_fee_amount' => $this->platformFeeCents($order),
            'transfer_data' => ['destination' => $tenant->stripe_account_id],
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'order_id' => (string) $order->id,
            ],
        ], [
            'idempotency_key' => "order_{$tenant->id}_{$order->id}",
        ]);

        $order->update(['stripe_payment_intent_id' => $intent->id]);

        return [
            'clientSecret' => $intent->client_secret,
            'publishableKey' => config('services.stripe.key'),
        ];
    }

    /** Comissão da plataforma, em centavos. */
    private function platformFeeCents(Order $order): int
    {
        $percent = (float) config('services.stripe.platform_fee_percent', 5.0);

        return (int) round($order->total_cents * $percent / 100);
    }
}
