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
