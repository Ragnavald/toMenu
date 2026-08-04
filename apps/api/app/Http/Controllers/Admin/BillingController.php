<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InvalidCouponException;
use App\Http\Controllers\Controller;
use App\Jobs\EnsureStripeCustomer;
use App\Services\StripeMode;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Assinatura da loja na plataforma.
 *
 * Todas as ações são do owner: quem assina e cancela é quem paga. Um gerente
 * com acesso ao painel não deve conseguir cancelar a conta nem abrir o portal
 * de faturamento, que expõe dados de cobrança do dono.
 */
class BillingController extends Controller
{
    /** Estado da assinatura, para a tela e para o aviso de trial. */
    public function show(TenantContext $context, StripeMode $mode): JsonResponse
    {
        $tenant = $context->getOrFail();
        $plan = $tenant->plan;

        /*
         * Adianta a criação do cliente no Stripe enquanto o lojista lê a tela.
         *
         * Sem isto, esse round-trip acontece entre o clique em assinar e o
         * redirect — a janela em que a espera é percebida. Só para quem ainda
         * pode assinar: criar cliente para quem já assinou não adianta nada, e
         * o job confere de novo antes de chamar o Stripe.
         */
        $linkageMatches = $tenant->stripeLinkageMatchesMode($mode->current());

        if ((! $tenant->stripe_customer_id || ! $linkageMatches)
            && $mode->isConfigured()
            && $plan?->stripe_price_id) {
            EnsureStripeCustomer::dispatch($tenant->id);
        }

        return response()->json([
            'plan' => [
                'slug' => $plan?->slug,
                'name' => $plan?->name,
                'priceCents' => $plan?->price_cents,
            ],
            /*
             * Assinatura de outro ambiente é reportada como inexistente.
             *
             * A tela usa estes campos para decidir entre "assinar" e "gerenciar
             * assinatura". Um `active` vindo do modo anterior levaria o lojista
             * ao portal, que recusa logo abaixo — beco sem saída. Reportar como
             * não-assinante oferece o caminho que de fato funciona: assinar de
             * novo, agora no ambiente ativo.
             *
             * Isto é só a APRESENTAÇÃO: `hasActiveSubscription()` continua
             * ignorando o modo, e é ele que os middlewares consultam para
             * liberar o painel. Quem já paga não perde acesso por conta de um
             * `.env` trocado.
             */
            'status' => $linkageMatches ? $tenant->subscription_status : null,
            'subscribed' => $linkageMatches && $tenant->hasSubscribed(),
            'active' => $linkageMatches && $tenant->hasActiveSubscription(),
            'trialEndsAt' => $tenant->trial_ends_at?->toIso8601String(),
            'trialDaysLeft' => $tenant->trialDaysLeft(),
            'trialExpired' => $tenant->trialHasExpired(),
            'currentPeriodEndsAt' => $tenant->current_period_ends_at?->toIso8601String(),
            'mode' => $mode->current(),
            // Sem chaves ou sem price id não há como assinar; a tela esconde o
            // botão em vez de levar o lojista a um erro.
            'available' => $mode->isConfigured() && $plan?->stripe_price_id !== null,
        ]);
    }

    /**
     * Abre o Checkout hospedado e devolve a URL para o painel redirecionar.
     *
     * O `SubscriptionService` é resolvido no corpo, e NÃO injetado na
     * assinatura, pelo mesmo motivo do TenantDeleter: ele depende do
     * StripeClient, que lança no construtor quando não há credencial. Injetado,
     * a exceção acontece antes da primeira linha do método e derruba com 500 os
     * guards abaixo — inclusive o 503 que existe justamente para responder a
     * uma instalação sem Stripe configurado.
     */
    public function checkout(Request $request, TenantContext $context, StripeMode $mode): JsonResponse
    {
        $tenant = $context->getOrFail();

        $data = $request->validate([
            // Limite generoso: o código é escolhido por quem cria a campanha,
            // não pelo lojista. Serve para barrar payload absurdo, não para
            // impor formato.
            'coupon' => ['nullable', 'string', 'max:100'],
        ]);

        abort_unless(auth()->user()?->isOwner(), 403, 'Apenas o dono da loja pode assinar.');

        if (! $mode->isConfigured()) {
            return response()->json([
                'message' => 'A cobrança não está configurada nesta instalação.',
            ], 503);
        }

        // Assinar de novo com uma assinatura ativa criaria uma segunda cobrança
        // mensal para a mesma loja. Quem já assina troca de plano ou cancela
        // pelo portal.
        // O modo entra na condição para não trancar quem tem assinatura ativa
        // no ambiente ANTERIOR: aquela assinatura não vale aqui, e sem esta
        // ressalva o lojista ficaria impedido de assinar no ambiente atual.
        if ($tenant->hasActiveSubscription() && $tenant->stripeLinkageMatchesMode($mode->current())) {
            return response()->json([
                'message' => 'Esta loja já tem uma assinatura ativa.',
            ], 422);
        }

        $panel = rtrim((string) config('tenancy.admin_url'), '/');

        try {
            $url = app(SubscriptionService::class)->createCheckoutSession(
                $tenant,
                // O `success` não confirma nada sozinho: quem ativa a
                // assinatura é o webhook. A tela só reconsulta o estado.
                successUrl: "{$panel}/assinatura?status=sucesso",
                cancelUrl: "{$panel}/assinatura?status=cancelado",
                promotionCode: $data['coupon'] ?? null,
            );
        } catch (InvalidCouponException $e) {
            /*
             * Precisa vir ANTES do catch abaixo: InvalidCouponException estende
             * RuntimeException, e na ordem inversa um cupom recusado viraria
             * 502 "não foi possível abrir o pagamento" — mandando o lojista
             * tentar de novo um código que nunca vai funcionar.
             */
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Assinatura: falha ao abrir o Checkout.', [
                'tenant_id' => $tenant->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível abrir o pagamento. Tente novamente.',
            ], 502);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Valida um cupom e devolve o desconto, antes de assinar.
     *
     * Existe para que o lojista veja quanto vai pagar sem sair do painel. O
     * código é revalidado na criação da sessão: entre ver e clicar, o cupom
     * pode esgotar.
     */
    public function coupon(Request $request, TenantContext $context, StripeMode $mode): JsonResponse
    {
        $tenant = $context->getOrFail();

        abort_unless(auth()->user()?->isOwner(), 403, 'Apenas o dono da loja pode aplicar cupom.');

        $data = $request->validate([
            'coupon' => ['required', 'string', 'max:100'],
        ]);

        if (! $mode->isConfigured()) {
            return response()->json([
                'message' => 'A cobrança não está configurada nesta instalação.',
            ], 503);
        }

        try {
            $preview = app(SubscriptionService::class)->previewCoupon($tenant, $data['coupon']);
        } catch (InvalidCouponException $e) {
            // Mesma ordem de catch do checkout, e pelo mesmo motivo.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Assinatura: falha ao validar cupom.', [
                'tenant_id' => $tenant->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível validar o cupom agora. Tente novamente.',
            ], 502);
        }

        return response()->json($preview);
    }

    /** Portal do Stripe: trocar cartão, ver faturas, cancelar. */
    public function portal(TenantContext $context, StripeMode $mode): JsonResponse
    {
        $tenant = $context->getOrFail();

        abort_unless(auth()->user()?->isOwner(), 403, 'Apenas o dono da loja pode gerenciar a assinatura.');

        if (! $tenant->stripe_customer_id) {
            return response()->json([
                'message' => 'Esta loja ainda não tem assinatura.',
            ], 422);
        }

        /*
         * Vínculo de outro ambiente do Stripe: o portal não abre.
         *
         * Diferente do checkout, aqui NÃO dá para recriar e seguir: um portal
         * de faturamento serve para ver faturas e trocar o cartão de uma
         * assinatura que existe. Um customer novo e vazio abriria uma tela sem
         * histórico nenhum, o que é mais confuso que a recusa.
         */
        if (! $tenant->stripeLinkageMatchesMode($mode->current())) {
            return response()->json([
                'message' => 'A assinatura desta loja pertence a outro ambiente de cobrança. '
                    .'Assine novamente para gerenciar o pagamento.',
            ], 422);
        }

        $panel = rtrim((string) config('tenancy.admin_url'), '/');

        try {
            $url = app(SubscriptionService::class)
                ->createPortalSession($tenant, "{$panel}/assinatura");
        } catch (ApiErrorException|\RuntimeException $e) {
            Log::error('Assinatura: falha ao abrir o portal.', [
                'tenant_id' => $tenant->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível abrir o portal de cobrança.',
            ], 502);
        }

        return response()->json(['url' => $url]);
    }
}
