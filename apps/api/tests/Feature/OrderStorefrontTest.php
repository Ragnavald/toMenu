<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'loja-b']);
});

it('retorna os detalhes do pedido para acompanhamento pelo cliente', function () {
    actingAsTenant($this->tenantA);

    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    $order = Order::create([
        'tenant_id' => $this->tenantA->id,
        'number' => 101,
        'status' => 'preparing',
        'fulfillment' => 'delivery',
        'payment_method' => 'cash',
        'payment_status' => 'pending',
        'subtotal_cents' => 4500,
        'delivery_fee_cents' => 0,
        'total_cents' => 4500,
        'placed_at' => now(),
    ]);

    OrderItem::create([
        'tenant_id' => $this->tenantA->id,
        'order_id' => $order->id,
        'product_name' => $product->name,
        'quantity' => 2,
        'unit_price_cents' => 2250,
        'total_cents' => 4500,
    ]);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->getJson("/api/orders/{$order->id}");

    $response->assertOk()
        ->assertJsonPath('id', $order->id)
        ->assertJsonPath('number', 101)
        ->assertJsonPath('status', 'preparing')
        ->assertJsonPath('totalCents', 4500)
        ->assertJsonCount(1, 'items');
});

it('impede consultar pedido de outro tenant', function () {
    actingAsTenant($this->tenantB);
    $orderB = Order::create([
        'tenant_id' => $this->tenantB->id,
        'number' => 1,
        'status' => 'confirmed',
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'payment_status' => 'pending',
        'subtotal_cents' => 1000,
        'delivery_fee_cents' => 0,
        'total_cents' => 1000,
        'placed_at' => now(),
    ]);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson("/api/orders/{$orderB->id}")
        ->assertNotFound();
});

/*
 * Regressão: em produção o pedido só resolve a loja pelo path.
 *
 * O storefront chama a API no browser em NEXT_PUBLIC_API_URL, que é
 * `api.{dominio}` — subdomínio reservado, recusado de propósito pelo
 * IdentifyTenant. Com TENANCY_TRUST_HEADER=false o X-Tenant também não vale, e
 * a rota sem `{tenant}` ficava sem nenhum caminho de resolução: todo pedido
 * respondia 404 "Loja não encontrada" no último passo do checkout.
 *
 * Os testes acima usam X-Tenant e por isso não pegavam a falha — passavam com
 * a configuração de desenvolvimento enquanto a produção estava quebrada.
 */
it('cria pedido pelo path quando o header de tenant é ignorado', function () {
    config(['tenancy.trust_header' => false]);

    actingAsTenant($this->tenantA);
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price_cents' => 2000,
        'is_available' => true,
    ]);
    forgetTenant();

    $payload = [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ];

    // Sem o slug no path não há como identificar a loja.
    $this->postJson('/api/orders', $payload)->assertNotFound();

    // Com o slug no path o pedido é criado normalmente.
    $this->postJson("/api/{$this->tenantA->slug}/orders", $payload)
        ->assertCreated();
});

it('acompanha o pedido pelo path quando o header de tenant é ignorado', function () {
    config(['tenancy.trust_header' => false]);

    actingAsTenant($this->tenantA);
    $order = Order::create([
        'tenant_id' => $this->tenantA->id,
        'number' => 202,
        'status' => 'preparing',
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'payment_status' => 'pending',
        'subtotal_cents' => 1000,
        'delivery_fee_cents' => 0,
        'total_cents' => 1000,
        'placed_at' => now(),
    ]);
    forgetTenant();

    $this->getJson("/api/orders/{$order->id}")->assertNotFound();

    $this->getJson("/api/{$this->tenantA->slug}/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('number', 202);
});

// O path não pode virar desvio do gate de plano: a loja somente-cardápio
// continua recusada, agora pela rota nova.
it('mantém o gate de plano na rota com tenant no path', function () {
    config(['tenancy.trust_header' => false]);

    $menuOnly = Tenant::factory()->create(['slug' => 'so-cardapio']);
    $menuOnly->plan->update(['allows_orders' => false]);

    $this->postJson("/api/{$menuOnly->slug}/orders", [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [],
    ])->assertForbidden();
});
