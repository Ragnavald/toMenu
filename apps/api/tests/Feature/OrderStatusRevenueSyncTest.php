<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinanceReportService;
use App\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/**
 * Mudar o status do pedido mantém o financeiro em dia.
 *
 * Sem pagamento pela plataforma, nada além do próprio status diz que o dinheiro
 * entrou: o pedido nasce `pending` e não existe webhook para confirmá-lo depois.
 * Como a receita exige `delivered` + `paid`, a falha que estes testes guardam é
 * silenciosa nos dois sentidos — ou o faturamento fica permanentemente vazio, ou
 * um clique corrigido no painel deixa para trás receita que não aconteceu.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->user);
});

function makeOrderForStatus(Tenant $tenant, array $attributes = []): Order
{
    return app(TenantContext::class)->runFor($tenant, function () use ($attributes) {
        $customer = Customer::firstOrCreate(
            ['phone' => '11990000000'],
            ['name' => 'Ana Souza'],
        );

        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'number' => (Order::max('number') ?? 0) + 1,
            'status' => 'out_for_delivery',
            'fulfillment' => 'delivery',
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'subtotal_cents' => 10000,
            'delivery_fee_cents' => 500,
            'total_cents' => 10500,
            'placed_at' => now(),
        ], $attributes));
    });
}

/** Receita do período pela mesma query que alimenta a tela e o CSV. */
function revenueCentsNow(Tenant $tenant): int
{
    return app(TenantContext::class)->runFor($tenant, fn () => app(FinanceReportService::class)
        ->summary(now()->startOfMonth(), now()->endOfMonth())['revenueCents']);
}

function patchStatus(Order $order, string $status)
{
    return test()->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => $status]);
}

it('marca como pago ao entregar um pedido pago na entrega', function () {
    $order = makeOrderForStatus($this->tenant);

    expect(revenueCentsNow($this->tenant))->toBe(0);

    patchStatus($order, 'delivered')->assertOk();

    $order->refresh();
    expect($order->payment_status)->toBe('paid')
        ->and($order->delivered_at)->not->toBeNull();

    // O que importa de verdade: o pedido passou a contar no faturamento.
    expect(revenueCentsNow($this->tenant))->toBe(10500);
});

it('tira o pedido da receita quando o status volta atrás', function () {
    $order = makeOrderForStatus($this->tenant);

    patchStatus($order, 'delivered')->assertOk();
    expect(revenueCentsNow($this->tenant))->toBe(10500);

    // O lojista percebe que clicou no card errado e corrige.
    patchStatus($order, 'preparing')->assertOk();

    expect($order->fresh()->payment_status)->toBe('pending')
        ->and(revenueCentsNow($this->tenant))->toBe(0);
});

it('tira o pedido da receita ao cancelar depois de entregue', function () {
    $order = makeOrderForStatus($this->tenant);

    patchStatus($order, 'delivered')->assertOk();
    patchStatus($order, 'cancelled')->assertOk();

    expect($order->fresh()->payment_status)->toBe('pending')
        ->and(revenueCentsNow($this->tenant))->toBe(0);
});

it('preserva o delivered_at original ao reentregar', function () {
    $order = makeOrderForStatus($this->tenant);

    patchStatus($order, 'delivered')->assertOk();
    $firstDeliveredAt = $order->fresh()->delivered_at;

    patchStatus($order, 'preparing')->assertOk();
    patchStatus($order, 'delivered')->assertOk();

    // A hora da entrega é um fato registrado, não um carimbo do último clique.
    expect($order->fresh()->delivered_at->eq($firstDeliveredAt))->toBeTrue();
});

it('não mexe no pagamento de pedido pago online', function () {
    // Pedidos antigos do Stripe continuam existindo. Ali o dinheiro entrou pelo
    // gateway e continua tendo entrado: o painel não pode desfazer isso.
    $order = makeOrderForStatus($this->tenant, [
        'payment_method' => 'stripe_card',
        'payment_status' => 'paid',
    ]);

    patchStatus($order, 'delivered')->assertOk();
    expect($order->fresh()->payment_status)->toBe('paid');

    patchStatus($order, 'cancelled')->assertOk();
    expect($order->fresh()->payment_status)->toBe('paid');
});

it('não inventa pagamento para pedido online ainda não pago', function () {
    $order = makeOrderForStatus($this->tenant, [
        'payment_method' => 'stripe_pix',
        'payment_status' => 'pending',
    ]);

    patchStatus($order, 'delivered')->assertOk();

    // Entregar não é receber quando quem confirma é o gateway.
    expect($order->fresh()->payment_status)->toBe('pending')
        ->and(revenueCentsNow($this->tenant))->toBe(0);
});
