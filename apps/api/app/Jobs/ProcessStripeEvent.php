<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Tenancy\TenantContext;
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
