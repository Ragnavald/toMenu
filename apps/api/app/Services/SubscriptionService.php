<?php

namespace App\Services;

use App\Exceptions\InvalidCouponException;
use App\Models\Tenant;
use RuntimeException;
use Stripe\PromotionCode;
use Stripe\StripeClient;
use Stripe\StripeObject;

/**
 * Assinatura do lojista na plataforma (a mensalidade do ToMenu).
 *
 * Não confundir com o Stripe Connect do `StripeConnectService`: lá o lojista
 * recebe dos clientes dele, aqui a plataforma cobra do lojista. São os dois
 * lados opostos do dinheiro e usam campos diferentes no tenant
 * (`stripe_account_id` contra `stripe_customer_id`).
 *
 * O cartão é coletado por Checkout hospedado pelo Stripe, e não por formulário
 * próprio: nenhum dado de cartão passa pela aplicação, e a recobrança, o 3DS e
 * a troca de cartão vencido ficam por conta do Stripe. O que volta para cá é o
 * webhook.
 */
class SubscriptionService
{
    public function __construct(
        private StripeClient $stripe,
        private StripeMode $mode,
    ) {}

    /**
     * Sessão de Checkout para assinar o plano do tenant.
     *
     * Devolve a URL hospedada para onde o painel redireciona.
     */
    public function createCheckoutSession(
        Tenant $tenant,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCode = null,
    ): string {
        $plan = $tenant->plan;

        if (! $plan?->stripe_price_id) {
            throw new RuntimeException(
                "Plano {$plan?->slug} sem stripe_price_id no modo {$this->mode->current()}."
            );
        }

        $customerId = $this->ensureCustomer($tenant);

        /*
         * O cupom é revalidado aqui, e não só na digitação.
         *
         * A validação da tela é informativa: entre ver o desconto e clicar em
         * assinar, o código pode ter esgotado o limite de usos ou expirado. Sem
         * reconferir, a sessão seria criada com um id inválido e o Stripe
         * recusaria com um erro que o lojista não sabe interpretar.
         */
        $discounts = [];

        if ($promotionCode !== null && $promotionCode !== '') {
            $promotion = $this->findPromotionCode($promotionCode);

            if ($promotion === null) {
                throw new InvalidCouponException;
            }

            $discounts = [['promotion_code' => $promotion->id]];
        }

        // `array_filter` aqui existe para remover `discounts` quando não há
        // cupom: o Stripe recusa a chave presente e vazia. Os demais valores
        // são strings ou arrays não vazios e passam intactos.
        $session = $this->stripe->checkout->sessions->create(array_filter([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [[
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]],
            /*
             * `discounts` e `allow_promotion_codes` são mutuamente exclusivos
             * no Stripe. Como o cupom é digitado no painel — onde o lojista vê
             * o valor com desconto antes de decidir —, o campo dentro da página
             * do Stripe fica de fora; enviar os dois faz a criação da sessão
             * falhar.
             */
            'discounts' => $discounts,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            /*
             * O trial que sobra vira trial no Stripe: o lojista que assina no
             * quinto dia não deve ser cobrado na hora e perder os nove dias
             * restantes. Sem isto, assinar cedo custaria dinheiro, e o lojista
             * aprenderia a esperar o último dia.
             */
            'subscription_data' => array_filter([
                'trial_period_days' => $this->remainingTrialDays($tenant),
                'metadata' => ['tenant_id' => (string) $tenant->id],
            ]),
            /*
             * O metadata é o que liga o evento de volta ao tenant. O webhook
             * chega sem contexto nenhum: sem ele o pagamento é órfão e não há
             * como saber qual loja acabou de assinar.
             */
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'plan_slug' => (string) $plan->slug,
            ],
            'client_reference_id' => (string) $tenant->id,
        ]));

        return $session->url;
    }

    /**
     * Valida um código de cupom e descreve o desconto para a tela.
     *
     * O lojista precisa ver quanto vai pagar ANTES de sair do painel: um código
     * aceito em silêncio, cujo efeito só aparece na página do Stripe, é o tipo
     * de coisa que gera chamado de suporte ("o desconto não entrou").
     *
     * @return array{code:string,description:string,priceCents:int|null}
     *
     * @throws InvalidCouponException quando o código não existe ou não vale mais
     */
    public function previewCoupon(Tenant $tenant, string $code): array
    {
        $promotion = $this->findPromotionCode($code);

        if ($promotion === null) {
            throw new InvalidCouponException;
        }

        $coupon = $promotion->coupon;
        $priceCents = $tenant->plan?->price_cents;

        return [
            // O código como o Stripe o guarda, e não como foi digitado: a
            // comparação é case-insensitive e a tela deve exibir o oficial.
            'code' => $promotion->code,
            'description' => $this->describeCoupon($coupon),
            'priceCents' => $priceCents !== null
                ? $this->discountedCents($priceCents, $coupon)
                : null,
        ];
    }

    /**
     * Busca o promotion code ativo correspondente ao código digitado.
     *
     * `active: true` deixa o Stripe filtrar o que está desligado; o resto das
     * condições (expirado, esgotado) é conferido aqui porque a API não as
     * aplica nesse filtro.
     */
    private function findPromotionCode(string $code): ?PromotionCode
    {
        $codes = $this->stripe->promotionCodes->all([
            // O Stripe casa este parâmetro sem diferenciar maiúsculas, o que
            // poupa normalizar o que o lojista digitou.
            'code' => trim($code),
            'active' => true,
            'limit' => 1,
        ]);

        $promotion = $codes->data[0] ?? null;

        if ($promotion === null || ! $this->promotionIsUsable($promotion)) {
            return null;
        }

        return $promotion;
    }

    /** O código ainda pode ser resgatado? */
    private function promotionIsUsable(PromotionCode $promotion): bool
    {
        // `expires_at` e `max_redemptions` vivem no promotion code; o cupom por
        // trás tem os seus próprios (`redeem_by`, `max_redemptions`). Os dois
        // precisam valer, e o Stripe não checa nenhum no filtro do `all`.
        if ($promotion->expires_at !== null && $promotion->expires_at < time()) {
            return false;
        }

        if ($promotion->max_redemptions !== null
            && $promotion->times_redeemed >= $promotion->max_redemptions) {
            return false;
        }

        $coupon = $promotion->coupon;

        if (! $coupon || ! $coupon->valid) {
            return false;
        }

        if ($coupon->redeem_by !== null && $coupon->redeem_by < time()) {
            return false;
        }

        return true;
    }

    /**
     * Texto do desconto, em português, para a tela de assinatura.
     *
     * O tipo é `StripeObject`, e não `Coupon`: objetos aninhados numa resposta
     * do Stripe são desserializados como StripeObject genérico, mesmo quando
     * representam um cupom. Exigir `Coupon` aqui estoura TypeError com a
     * resposta real da API.
     */
    private function describeCoupon(StripeObject $coupon): string
    {
        $valor = $coupon->percent_off !== null
            // O Stripe devolve float (50.0); sem desconto de centavos o texto
            // fica "50%" em vez de "50.0%".
            ? rtrim(rtrim(number_format((float) $coupon->percent_off, 2, ',', '.'), '0'), ',').'%'
            : 'R$ '.number_format(((int) $coupon->amount_off) / 100, 2, ',', '.');

        return match ($coupon->duration) {
            'forever' => "{$valor} de desconto para sempre",
            'once' => "{$valor} de desconto no primeiro mês",
            'repeating' => "{$valor} de desconto por {$coupon->duration_in_months} meses",
            default => "{$valor} de desconto",
        };
    }

    /** Valor da mensalidade já com o desconto, em centavos. */
    /** Ver a nota de tipo em `describeCoupon`. */
    private function discountedCents(int $priceCents, StripeObject $coupon): int
    {
        if ($coupon->percent_off !== null) {
            $discount = (int) round($priceCents * ((float) $coupon->percent_off) / 100);

            return max(0, $priceCents - $discount);
        }

        // Um `amount_off` maior que a mensalidade zera a fatura, não a torna
        // negativa.
        return max(0, $priceCents - (int) $coupon->amount_off);
    }

    /**
     * Portal do Stripe para trocar cartão, ver faturas e cancelar.
     *
     * Construir essas telas no painel seria reimplementar o que o Stripe já
     * entrega pronto — inclusive as faturas em PDF, que o lojista precisa para
     * a contabilidade.
     */
    public function createPortalSession(Tenant $tenant, string $returnUrl): string
    {
        if (! $tenant->stripe_customer_id) {
            throw new RuntimeException("Tenant {$tenant->id} não tem cliente no Stripe.");
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $tenant->stripe_customer_id,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /**
     * Garante o cliente no Stripe, reaproveitando o já existente.
     *
     * Criar um cliente novo a cada tentativa de assinatura espalharia o
     * histórico de cobrança da mesma loja por vários customers, e o portal
     * mostraria só uma fatia das faturas.
     *
     * Público porque o `EnsureStripeCustomer` chama isto em fila, quando a tela
     * de assinatura abre, para que o clique em assinar não pague este
     * round-trip. O checkout continua chamando por conta própria: o job é
     * oportunista e pode não ter rodado ainda.
     */
    public function ensureCustomer(Tenant $tenant): string
    {
        if ($tenant->stripe_customer_id) {
            return $tenant->stripe_customer_id;
        }

        /*
         * O job e o clique podem correr juntos — o lojista que abre a tela e
         * clica em seguida dispara os dois. Reler dentro do lock e conferir de
         * novo evita o cliente duplicado que o `uniqueId` do job sozinho não
         * cobre, porque o checkout não passa por ele.
         */
        return $tenant->getConnection()->transaction(function () use ($tenant) {
            $fresh = Tenant::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($tenant->id);

            if ($fresh?->stripe_customer_id) {
                // Mantém o model do chamador coerente com o banco.
                $tenant->stripe_customer_id = $fresh->stripe_customer_id;

                return $fresh->stripe_customer_id;
            }

            return $this->createCustomer($tenant);
        });
    }

    /** Cria o cliente no Stripe e guarda o id no tenant. */
    private function createCustomer(Tenant $tenant): string
    {
        $owner = $tenant->users()->where('role', 'owner')->first();

        $customer = $this->stripe->customers->create([
            'name' => $tenant->name,
            'email' => $owner?->email,
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'slug' => $tenant->slug,
            ],
        ]);

        $tenant->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    /**
     * Dias de trial que ainda restam, para repassar ao Stripe.
     *
     * Null quando não há trial a preservar — `array_filter` remove a chave e o
     * Stripe cobra na hora, que é o correto para quem já passou do prazo.
     */
    private function remainingTrialDays(Tenant $tenant): ?int
    {
        $days = $tenant->trialDaysLeft();

        // O Stripe exige no mínimo 1 dia; 0 ou negativo significa trial vencido.
        return $days !== null && $days >= 1 ? $days : null;
    }
}
