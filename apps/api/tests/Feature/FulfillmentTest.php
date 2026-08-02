<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;

/**
 * Modalidades de recebimento: entrega, retirada e consumo no local.
 *
 * O que a loja oferta é configuração, e o storefront desenha o seletor a partir
 * dela. Estes testes cobrem os dois lados que precisam concordar: o que a API
 * anuncia no cardápio e o que ela aceita no checkout.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);

    actingAsTenant($this->tenant);

    $this->settings = TenantSettings::create([
        'tenant_id' => $this->tenant->id,
        'delivery_config' => [
            'fee_cents' => 700,
            'accepts_delivery' => true,
            'accepts_pickup' => true,
            'accepts_dine_in' => true,
        ],
    ]);

    $category = Category::factory()->create();
    $this->product = Product::factory()->create([
        'category_id' => $category->id,
        'price_cents' => 5000,
    ]);

    forgetTenant();
});

/** Reconfigura a loja e derruba o cardápio cacheado da versão anterior. */
function configureFulfillments(Tenant $tenant, array $config): void
{
    actingAsTenant($tenant);
    $settings = $tenant->settings;
    $settings->delivery_config = array_merge($settings->delivery_config, $config);
    $settings->save();
    forgetTenant();

    $tenant->refresh();
}

function fulfillmentPayload(string $fulfillment, int $productId): array
{
    $payload = [
        'customer' => ['name' => 'João', 'phone' => '11999998888'],
        'fulfillment' => $fulfillment,
        'payment_method' => 'cash',
        'items' => [['product_id' => $productId, 'quantity' => 1]],
    ];

    if ($fulfillment === 'delivery') {
        $payload['address'] = [
            'street' => 'Rua A',
            'city' => 'São Paulo',
            'state' => 'SP',
        ];
    }

    return $payload;
}

it('aceita pedido para consumo no local', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', fulfillmentPayload('dine_in', $this->product->id))
        ->assertCreated()
        ->assertJsonPath('fulfillment', 'dine_in');
});

it('não cobra taxa de entrega no consumo no local', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', fulfillmentPayload('dine_in', $this->product->id))
        ->assertCreated()
        // 5000 do item, sem os 700 da entrega.
        ->assertJsonPath('totalCents', 5000);
});

it('não exige endereço no consumo no local', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', fulfillmentPayload('dine_in', $this->product->id))
        ->assertCreated();

    actingAsTenant($this->tenant);

    expect(Order::first()->address_id)->toBeNull();
});

it('recusa modalidade que a loja não oferta', function () {
    configureFulfillments($this->tenant, ['accepts_dine_in' => false]);

    // O storefront não desenha o botão, mas o POST é público: sem esta checagem
    // um payload montado à mão criaria o pedido mesmo assim.
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', fulfillmentPayload('dine_in', $this->product->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('fulfillment');
});

it('recusa entrega quando a loja só atende no balcão', function () {
    configureFulfillments($this->tenant, ['accepts_delivery' => false]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', fulfillmentPayload('delivery', $this->product->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('fulfillment');
});

it('recusa modalidade desconhecida', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', fulfillmentPayload('drone', $this->product->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('fulfillment');
});

it('anuncia no cardápio as modalidades ofertadas', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.fulfillments', ['delivery', 'pickup', 'dine_in']);
});

it('omite do cardápio a modalidade desligada', function () {
    configureFulfillments($this->tenant, ['accepts_dine_in' => false]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.fulfillments', ['delivery', 'pickup']);
});

it('não oferta consumo no local para loja que nunca configurou', function () {
    $legacy = Tenant::factory()->create(['slug' => 'loja-antiga']);

    actingAsTenant($legacy);
    TenantSettings::create([
        'tenant_id' => $legacy->id,
        // Configuração no formato anterior ao recurso: sem nenhuma flag.
        'delivery_config' => ['fee_cents' => 500],
    ]);
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);
    forgetTenant();

    // Entrega e retirada seguem ligadas; consumo no local é opt-in, para que o
    // recurso não apareça sozinho na loja de quem não tem salão.
    $this->withHeader('X-Tenant', 'loja-antiga')
        ->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.fulfillments', ['delivery', 'pickup']);
});
