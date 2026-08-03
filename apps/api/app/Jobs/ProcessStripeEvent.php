<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processamento assíncrono dos eventos do Stripe.
 *
 * Roda fora do ciclo HTTP para que o webhook responda dentro da janela de 20s
 * do Stripe mesmo quando o processamento é pesado.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var int[] */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public string $eventId) {}

    public function handle(TenantContext $context): void
    {
        $record = WebhookEvent::where('provider', 'stripe')
            ->where('event_id', $this->eventId)
            ->first();

        if (! $record || $record->processed_at !== null) {
            return; // Já processado; proteção extra além do unique.
        }

        $payload = $record->payload;
        $object = $payload['data']['object'] ?? [];

        match ($record->type) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($context, $object),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($context, $object),
            'account.updated' => $this->handleAccountUpdated($object),

            // Assinatura da loja na plataforma (a mensalidade).
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionChanged($object),
            'invoice.payment_failed' => $this->handleInvoiceFailed($object),

            default => null,
        };

        $record->update(['processed_at' => now()]);
    }

    private function handlePaymentSucceeded(TenantContext $context, array $intent): void
    {
        [$tenant, $orderId] = $this->resolveTarget($intent);

        if (! $tenant) {
            return;
        }

        $context->runFor($tenant, function () use ($intent, $orderId, $tenant) {
            $order = Order::find($orderId);

            if (! $order || $order->isPaid()) {
                return;
            }

            // Revalidação do valor contra o banco. O PaymentIntent poderia ter
            // sido criado com valor divergente do pedido; confirmar sem checar
            // permitiria pagar R$ 1 por um pedido de R$ 100.
            $paid = (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0);

            if ($paid < $order->total_cents) {
                Log::warning('Stripe: valor pago menor que o total do pedido.', [
                    'order_id' => $order->id,
                    'expected' => $order->total_cents,
                    'received' => $paid,
                ]);

                return;
            }

            DB::transaction(function () use ($order, $intent, $paid) {
                $order->update([
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);

                Payment::create([
                    'order_id' => $order->id,
                    'provider' => 'stripe',
                    'provider_payment_id' => $intent['id'] ?? null,
                    'amount_cents' => $paid,
                    'application_fee_cents' => (int) ($intent['application_fee_amount'] ?? 0),
                    'status' => 'succeeded',
                    'raw_payload' => $intent,
                ]);
            });

            // Só agora a cozinha é avisada: o dinheiro está confirmado.
            NotifyNewOrder::dispatch($order->id, $tenant->id);
        });
    }

    private function handlePaymentFailed(TenantContext $context, array $intent): void
    {
        [$tenant, $orderId] = $this->resolveTarget($intent);

        if (! $tenant) {
            return;
        }

        $context->runFor($tenant, function () use ($orderId) {
            Order::where('id', $orderId)->update(['payment_status' => 'failed']);
        });
    }

    /**
     * Vincula a assinatura recém-criada ao tenant.
     *
     * É o único evento que traz o `tenant_id` no metadata que a plataforma
     * gravou. Os eventos seguintes de `customer.subscription.*` são encontrados
     * pelo `stripe_subscription_id` salvo aqui.
     */
    private function handleCheckoutCompleted(array $session): void
    {
        // Sessões de outros modos (`payment`, `setup`) não criam assinatura.
        if (($session['mode'] ?? null) !== 'subscription') {
            return;
        }

        $tenantId = $session['metadata']['tenant_id'] ?? $session['client_reference_id'] ?? null;
        $subscriptionId = $session['subscription'] ?? null;

        if (! $tenantId || ! $subscriptionId) {
            Log::warning('Stripe: checkout de assinatura sem tenant ou assinatura.', [
                'session' => $session['id'] ?? null,
            ]);

            return;
        }

        // withoutGlobalScopes: não há contexto de tenant no webhook.
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);

        if (! $tenant) {
            return;
        }

        $tenant->update([
            'stripe_subscription_id' => $subscriptionId,
            'stripe_customer_id' => $session['customer'] ?? $tenant->stripe_customer_id,
        ]);
    }

    /**
     * Espelha o estado da assinatura vindo do Stripe.
     *
     * Fonte da verdade é o Stripe, não a aplicação: renovação, falha de cartão
     * e cancelamento acontecem lá, e tentar deduzir o estado por aqui daria uma
     * loja marcada como em dia com o cartão recusado há semanas.
     */
    private function handleSubscriptionChanged(array $subscription): void
    {
        $tenant = $this->tenantForSubscription($subscription);

        if (! $tenant) {
            return;
        }

        $status = (string) ($subscription['status'] ?? '');
        $periodEnd = $subscription['current_period_end'] ?? null;

        $attributes = [
            'subscription_status' => $status,
            'current_period_ends_at' => $periodEnd
                ? CarbonImmutable::createFromTimestamp($periodEnd)
                : null,
        ];

        /*
         * Assinatura ativa tira a loja de `past_due` — inclusive quando a
         * regularização veio pelo portal do Stripe, sem passar pela aplicação.
         *
         * A loja suspensa NÃO é reativada aqui: a suspensão é decisão do staff
         * e pode ter outro motivo (abuso, pedido do titular). Pagar a fatura
         * não deve desfazer sozinho uma decisão administrativa.
         */
        if (in_array($status, ['active', 'trialing'], true) && $tenant->status === 'past_due') {
            $attributes['status'] = 'active';
        }

        $tenant->update($attributes);
    }

    /**
     * Fatura não paga: marca a loja como inadimplente.
     *
     * A loja continua no ar de propósito. Tirar do ar um restaurante no meio do
     * almoço por um cartão recusado é dano desproporcional, e o Stripe ainda
     * vai tentar recobrar nos próximos dias. A suspensão continua sendo ação
     * manual do staff.
     */
    private function handleInvoiceFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $tenant = Tenant::withoutGlobalScopes()
            ->where('stripe_subscription_id', $subscriptionId)
            ->first();

        if (! $tenant || $tenant->status === 'suspended') {
            return;
        }

        $tenant->update([
            'status' => 'past_due',
            'subscription_status' => 'past_due',
        ]);

        Log::warning('Assinatura: fatura não paga.', [
            'tenant_id' => $tenant->id,
            'invoice' => $invoice['id'] ?? null,
        ]);
    }

    /**
     * Encontra o tenant de um evento de assinatura.
     *
     * Pelo id da assinatura primeiro, que é o vínculo salvo no checkout; o
     * metadata é o fallback para a assinatura criada direto no dashboard do
     * Stripe, que nunca passou pelo nosso checkout.
     */
    private function tenantForSubscription(array $subscription): ?Tenant
    {
        $id = $subscription['id'] ?? null;

        if ($id) {
            $tenant = Tenant::withoutGlobalScopes()
                ->where('stripe_subscription_id', $id)
                ->first();

            if ($tenant) {
                return $tenant;
            }
        }

        $tenantId = $subscription['metadata']['tenant_id'] ?? null;

        return $tenantId ? Tenant::withoutGlobalScopes()->find($tenantId) : null;
    }

    /** Habilita pagamento online assim que o onboarding do Connect conclui. */
    private function handleAccountUpdated(array $account): void
    {
        Tenant::where('stripe_account_id', $account['id'] ?? '')
            ->update([
                'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            ]);
    }

    /**
     * O tenant vem do metadata gravado na criação do PaymentIntent.
     * withoutGlobalScopes é necessário: não há contexto de tenant ativo aqui.
     *
     * @return array{0:?Tenant,1:?int}
     */
    private function resolveTarget(array $intent): array
    {
        $tenantId = $intent['metadata']['tenant_id'] ?? null;
        $orderId = $intent['metadata']['order_id'] ?? null;

        if (! $tenantId || ! $orderId) {
            Log::warning('Stripe: evento sem metadata de tenant/pedido.', [
                'intent' => $intent['id'] ?? null,
            ]);

            return [null, null];
        }

        return [Tenant::withoutGlobalScopes()->find($tenantId), (int) $orderId];
    }
}
