<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeEvent;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Recepção de webhooks do Stripe.
 *
 * Esta rota NÃO passa por identify.tenant: o Stripe chama o domínio da
 * plataforma, não o do tenant. O tenant é recuperado do metadata do evento
 * dentro do job.
 *
 * Três garantias obrigatórias aqui:
 *   1. Assinatura verificada sobre o corpo BRUTO da request.
 *   2. Idempotência — o Stripe reenvia o mesmo evento em caso de timeout.
 *   3. Resposta rápida — o Stripe corta em 20s e passa a reenviar.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            // getContent() e não $request->all(): qualquer normalização do corpo
            // invalida o HMAC e a verificação passa a falhar sem motivo aparente.
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            // 400 sem detalhe: não confirmar a um chamador não autenticado
            // qual parte da verificação falhou.
            return response('', 400);
        }

        try {
            WebhookEvent::create([
                'provider' => 'stripe',
                'event_id' => $event->id,
                'type' => $event->type,
                'payload' => $event->toArray(),
            ]);
        } catch (QueryException) {
            // Violação do unique (provider, event_id) = evento já recebido.
            // Responder 200 evita retry infinito do Stripe.
            return response('', 200);
        }

        ProcessStripeEvent::dispatch($event->id);

        return response('', 200);
    }
}
