<?php

use App\Exceptions\InvalidCouponException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StripeMode;
use App\Services\SubscriptionService;
use Stripe\Collection;
use Stripe\PromotionCode;
use Stripe\Service\PromotionCodeService;
use Stripe\StripeClient;

/**
 * Cupom de desconto na mensalidade.
 *
 * O `StripeClient` é substituído por um duplo: o que se testa é a regra de
 * quando um código vale, e não a API do Stripe. As condições verificadas aqui
 * (expirado, esgotado) são justamente as que o filtro `active: true` do Stripe
 * NÃO aplica — por isso precisam de checagem própria, e de teste.
 */
beforeEach(function () {
    config([
        'services.stripe.mode' => 'test',
        'services.stripe.test.key' => 'pk_test_fake',
        'services.stripe.test.secret' => 'sk_test_fake',
        'services.stripe.test.webhook_secret' => 'whsec_fake',
    ]);

    $this->plan = Plan::factory()->create([
        'slug' => Plan::PRO,
        'price_cents' => 9900,
        'stripe_price_id' => 'price_test_pro',
    ]);

    $this->tenant = Tenant::factory()->create([
        'slug' => 'loja-a',
        'plan_id' => $this->plan->id,
        'trial_ends_at' => now()->addDays(10),
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
    ]);
});

/**
 * Monta um SubscriptionService cujo Stripe devolve o promotion code dado.
 *
 * `null` simula o código que não existe: a busca volta vazia.
 */
function serviceReturning(?array $promotion): SubscriptionService
{
    $codes = Mockery::mock(PromotionCodeService::class);
    $codes->shouldReceive('all')->andReturn(
        Collection::constructFrom([
            'data' => $promotion ? [PromotionCode::constructFrom($promotion)] : [],
        ])
    );

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->promotionCodes = $codes;

    return new SubscriptionService($stripe, new StripeMode);
}

/** Promotion code válido, com o cupom por trás. */
function validPromotion(array $coupon = [], array $overrides = []): array
{
    return array_merge([
        'id' => 'promo_123',
        'code' => 'LANCAMENTO50',
        'active' => true,
        'expires_at' => null,
        'max_redemptions' => null,
        'times_redeemed' => 0,
        'coupon' => array_merge([
            'id' => 'coupon_123',
            'valid' => true,
            'percent_off' => 50.0,
            'amount_off' => null,
            'duration' => 'forever',
            'duration_in_months' => null,
            'redeem_by' => null,
        ], $coupon),
    ], $overrides);
}

it('calcula o preço com desconto percentual', function () {
    $preview = serviceReturning(validPromotion())
        ->previewCoupon($this->tenant, 'LANCAMENTO50');

    // 50% de 9900
    expect($preview['priceCents'])->toBe(4950)
        ->and($preview['code'])->toBe('LANCAMENTO50')
        ->and($preview['description'])->toBe('50% de desconto para sempre');
});

it('calcula o preço com desconto de valor fixo', function () {
    $preview = serviceReturning(validPromotion([
        'percent_off' => null,
        'amount_off' => 2000,
        'duration' => 'once',
    ]))->previewCoupon($this->tenant, 'VALE20');

    expect($preview['priceCents'])->toBe(7900)
        ->and($preview['description'])->toBe('R$ 20,00 de desconto no primeiro mês');
});

it('descreve desconto por tempo determinado', function () {
    $preview = serviceReturning(validPromotion([
        'duration' => 'repeating',
        'duration_in_months' => 3,
    ]))->previewCoupon($this->tenant, 'TRIMESTRE');

    expect($preview['description'])->toBe('50% de desconto por 3 meses');
});

it('não deixa o desconto tornar a mensalidade negativa', function () {
    // Um cupom de R$ 200 numa mensalidade de R$ 99 zera a fatura; um valor
    // negativo viraria crédito e o Stripe recusaria a sessão.
    $preview = serviceReturning(validPromotion([
        'percent_off' => null,
        'amount_off' => 20000,
    ]))->previewCoupon($this->tenant, 'GENEROSO');

    expect($preview['priceCents'])->toBe(0);
});

it('recusa código que não existe', function () {
    serviceReturning(null)->previewCoupon($this->tenant, 'NAOEXISTE');
})->throws(InvalidCouponException::class);

it('recusa código expirado', function () {
    // O filtro `active: true` do Stripe não cobre expiração; sem a checagem
    // própria, um código vencido passaria.
    serviceReturning(validPromotion([], ['expires_at' => now()->subDay()->timestamp]))
        ->previewCoupon($this->tenant, 'VENCIDO');
})->throws(InvalidCouponException::class);

it('recusa código que esgotou o limite de usos', function () {
    serviceReturning(validPromotion([], [
        'max_redemptions' => 100,
        'times_redeemed' => 100,
    ]))->previewCoupon($this->tenant, 'ESGOTADO');
})->throws(InvalidCouponException::class);

it('recusa cupom marcado como inválido pelo stripe', function () {
    serviceReturning(validPromotion(['valid' => false]))
        ->previewCoupon($this->tenant, 'INVALIDO');
})->throws(InvalidCouponException::class);

it('recusa cupom cuja data de resgate passou', function () {
    serviceReturning(validPromotion(['redeem_by' => now()->subDay()->timestamp]))
        ->previewCoupon($this->tenant, 'TARDE');
})->throws(InvalidCouponException::class);

it('devolve 422 e a mensagem quando o cupom é recusado', function () {
    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldReceive('previewCoupon')->andThrow(new InvalidCouponException);
    app()->instance(SubscriptionService::class, $service);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/coupon', ['coupon' => 'XX'], ['X-Tenant' => 'loja-a'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cupom inválido ou expirado.');
});

it('devolve o desconto pelo endpoint', function () {
    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldReceive('previewCoupon')->andReturn([
        'code' => 'LANCAMENTO50',
        'description' => '50% de desconto para sempre',
        'priceCents' => 4950,
    ]);
    app()->instance(SubscriptionService::class, $service);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/coupon', ['coupon' => 'lancamento50'], ['X-Tenant' => 'loja-a'])
        ->assertOk()
        ->assertJsonPath('priceCents', 4950);
});

it('recusa cupom aplicado por quem não é dono', function () {
    $membro = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
    ]);

    $this->actingAs($membro, 'sanctum')
        ->postJson('/api/admin/billing/coupon', ['coupon' => 'XX'], ['X-Tenant' => 'loja-a'])
        ->assertForbidden();
});

it('não confunde cupom recusado com falha do stripe no checkout', function () {
    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldReceive('createCheckoutSession')->andThrow(new InvalidCouponException);
    app()->instance(SubscriptionService::class, $service);

    /*
     * InvalidCouponException estende RuntimeException, e o catch genérico do
     * controller responde 502. Na ordem errada, um cupom recusado viraria
     * "tente novamente" — mandando o lojista repetir um código que nunca vai
     * funcionar.
     */
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/checkout', ['coupon' => 'ESGOTADO'], ['X-Tenant' => 'loja-a'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cupom inválido ou expirado.');
});
