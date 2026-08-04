<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinanceReportService;
use App\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/**
 * Arquivar tira o pedido do painel sem alterar o que ele foi.
 *
 * O risco que estes testes guardam é o do arquivamento virar exclusão por
 * acidente: a receita do financeiro sai de `status = delivered`, e um pedido
 * finalizado que deixasse de contar viraria dinheiro sumido no fechamento do
 * restaurante — falha silenciosa, que só apareceria no fim do mês.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->user);
});

/** Roda o callback dentro do contexto do tenant, como faria o middleware. */
function runForTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runFor($tenant, $callback);
}

/** Cria um pedido no contexto do tenant do teste. */
function makeArchivableOrder(Tenant $tenant, array $attributes = []): Order
{
    return runForTenant($tenant, function () use ($attributes) {
        $customer = Customer::firstOrCreate(
            ['phone' => '11990000000'],
            ['name' => 'Ana Souza'],
        );

        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'number' => (Order::max('number') ?? 0) + 1,
            'status' => 'delivered',
            'fulfillment' => 'delivery',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal_cents' => 10000,
            'delivery_fee_cents' => 500,
            'total_cents' => 10500,
            'placed_at' => now(),
        ], $attributes));
    });
}

it('arquiva um pedido entregue e o tira do painel', function () {
    $order = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive")
        ->assertOk();

    expect($order->fresh()->archived_at)->not->toBeNull();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('mantém o status do pedido ao arquivar', function () {
    $order = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive");

    // O pedido continua entregue: arquivar é sobre a tela, não sobre o fluxo.
    expect($order->fresh()->status)->toBe('delivered');
});

it('mantém o pedido arquivado no relatório financeiro', function () {
    $order = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive");

    $revenue = runForTenant($this->tenant, fn () => app(FinanceReportService::class)
        ->revenueQuery(now()->subDay(), now()->addDay())
        ->sum('total_cents'));

    expect($revenue)->toBe(10500);
});

it('recusa arquivar um pedido que ainda não foi entregue', function () {
    $order = makeArchivableOrder($this->tenant, ['status' => 'preparing']);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive")
        ->assertStatus(422);

    expect($order->fresh()->archived_at)->toBeNull();
});

it('trata arquivar duas vezes como sucesso sem mudar a data', function () {
    $order = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive")
        ->assertOk();

    $firstArchivedAt = $order->fresh()->archived_at;

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive")
        ->assertOk();

    expect($order->fresh()->archived_at->timestamp)->toBe($firstArchivedAt->timestamp);
});

it('lista no histórico apenas os pedidos arquivados', function () {
    $archived = makeArchivableOrder($this->tenant);
    makeArchivableOrder($this->tenant); // Entregue, mas ainda no painel.

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$archived->id}/archive");

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/orders/archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $archived->id);
});

it('filtra o histórico pelo período de arquivamento', function () {
    $order = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$order->id}/archive");

    // O dia de hoje entra pelas duas pontas: é o caso que quebraria se o
    // filtro comparasse a meia-noite em vez do fim do dia.
    $todayIso = now()->toDateString();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson("/api/admin/orders/archived?from={$todayIso}&to={$todayIso}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $past = now()->subMonth()->toDateString();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson("/api/admin/orders/archived?from={$past}&to={$past}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('não deixa o curinga de tenant capturar a rota de admin', function () {
    /*
     * Regressão: `{tenant}/orders/{order}` do storefront casava com
     * `admin/orders/archived` e o IdentifyTenant respondia 404, porque `admin`
     * é subdomínio reservado. Ancorar na rota resolvida — e não só no status —
     * é o que diferencia esta falha de um 404 por dado ausente.
     */
    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/orders/archived')
        ->assertOk();

    expect(request()->route()->uri())->toBe('api/admin/orders/archived');
});

it('limpa o histórico sem tocar nos pedidos do painel', function () {
    $archived = makeArchivableOrder($this->tenant);
    $live = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$archived->id}/archive");

    $this->withHeader('X-Tenant', 'loja-a')
        ->deleteJson('/api/admin/orders/archived')
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    runForTenant($this->tenant, function () use ($archived, $live) {
        expect(Order::find($archived->id))->toBeNull();
        expect(Order::find($live->id))->not->toBeNull();
    });
});

it('não deixa a limpeza vazar para outra loja', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    $otherOrder = makeArchivableOrder($other, ['archived_at' => now()]);

    $mine = makeArchivableOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->patchJson("/api/admin/orders/{$mine->id}/archive");

    $this->withHeader('X-Tenant', 'loja-a')
        ->deleteJson('/api/admin/orders/archived')
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    runForTenant($other, function () use ($otherOrder) {
        expect(Order::find($otherOrder->id))->not->toBeNull();
    });
});
