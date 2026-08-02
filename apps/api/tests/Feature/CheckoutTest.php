<?php

use App\Jobs\NotifyNewOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);

    actingAsTenant($this->tenant);

    TenantSettings::create([
        'tenant_id' => $this->tenant->id,
        'delivery_config' => ['fee_cents' => 700],
    ]);

    $category = Category::factory()->create();
    $this->product = Product::factory()->create([
        'category_id' => $category->id,
        'price_cents' => 5000,
    ]);

    forgetTenant();
});

function orderPayload(array $overrides = []): array
{
    return array_merge([
        'customer' => ['name' => 'João', 'phone' => '11999998888'],
        'fulfillment' => 'delivery',
        'address' => ['street' => 'Rua A', 'city' => 'São Paulo', 'state' => 'SP'],
        'payment_method' => 'cash',
        'items' => [],
    ], $overrides);
}

it('calcula o total no servidor a partir do preço do banco', function () {
    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ]));

    // 2 x 5000 + 700 de entrega
    $response->assertCreated()->assertJsonPath('totalCents', 10700);
});

it('ignora preço enviado pelo cliente', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                // Tentativa de comprar por 1 centavo. Estes campos não estão no
                // FormRequest e o preço é sempre relido do banco.
                'price_cents' => 1,
                'unit_price_cents' => 1,
                'total_cents' => 1,
            ]],
        ]))
        ->assertCreated()
        ->assertJsonPath('totalCents', 5700);
});

it('recusa produto de outro tenant no carrinho', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($other);
    $categoryB = Category::factory()->create();
    $productOfB = Product::factory()->create(['category_id' => $categoryB->id]);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'items' => [['product_id' => $productOfB->id, 'quantity' => 1]],
        ]))
        ->assertStatus(422);
});

it('numera pedidos sequencialmente por tenant', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($other);
    $categoryB = Category::factory()->create();
    $productOfB = Product::factory()->create(['category_id' => $categoryB->id]);
    forgetTenant();

    $first = $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]));

    $otherStore = $this->withHeader('X-Tenant', 'loja-b')
        ->postJson('/api/orders', orderPayload([
            'items' => [['product_id' => $productOfB->id, 'quantity' => 1]],
        ]));

    // Cada loja tem sua própria sequência começando em 1.
    $first->assertJsonPath('number', 1);
    $otherStore->assertJsonPath('number', 1);
});

it('confirma imediatamente pedido para pagar na entrega', function () {
    Queue::fake();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('requiresPayment', false);

    // Sem gateway a confirmar, a cozinha é avisada na hora.
    Queue::assertPushed(NotifyNewOrder::class);
});

it('não avisa a cozinha antes da confirmação do pagamento online', function () {
    Queue::fake();

    $this->tenant->update([
        'stripe_account_id' => 'acct_test',
        'stripe_charges_enabled' => true,
    ]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'payment_method' => 'stripe_card',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'pending_payment');

    // O disparo acontece só no webhook payment_intent.succeeded. Avisar aqui
    // faria o restaurante preparar pedidos que podem nunca ser pagos.
    Queue::assertNotPushed(NotifyNewOrder::class);
});

it('recusa pagamento online quando o tenant não concluiu o onboarding', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'payment_method' => 'stripe_card',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]))
        ->assertStatus(422);
});

it('exige endereço quando a entrega é delivery', function () {
    $payload = orderPayload([
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ]);
    unset($payload['address']);

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', $payload)
        ->assertStatus(422);
});

it('grava snapshot do preço no item do pedido', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]))->assertCreated();

    actingAsTenant($this->tenant);

    // Reajuste posterior não pode alterar o histórico.
    $this->product->update(['price_cents' => 9900]);

    $item = Order::first()->items()->first();

    expect($item->unit_price_cents)->toBe(5000)
        ->and($item->product_name)->toBe($this->product->name);
});

/*
 * Corrida no cadastro do cliente.
 *
 * `firstOrCreate` faz SELECT e depois INSERT. Dois pedidos simultâneos do mesmo
 * telefone — duplo-clique em "Finalizar", ou duas abas — passam ambos pelo
 * SELECT vazio, e o segundo INSERT viola o unique (tenant_id, phone).
 *
 * Quem sustenta este caso é o `createOrFirst` do Eloquent, que envolve o INSERT
 * num savepoint e relê a linha quando a violação acontece. O savepoint é o
 * detalhe que importa no Postgres: sem ele a violação abortaria a transação do
 * pedido inteiro e um pedido válido viraria 500.
 *
 * O teste existe porque essa garantia é invisível na leitura do OrderService e
 * fácil de perder — trocar `firstOrCreate` por um SELECT seguido de `create()`,
 * ou mover a criação para fora da transação, reabre o furo sem nenhum sinal.
 * O concorrente é simulado inserindo a linha exatamente na janela entre as duas
 * operações.
 */
it('não perde o pedido quando o cliente é criado por uma request concorrente', function () {
    $phone = '11999998888';
    $inserted = false;

    DB::listen(function ($query) use ($phone, &$inserted) {
        if ($inserted || ! str_contains($query->sql, 'select * from "customers"')) {
            return;
        }

        if (! in_array($phone, $query->bindings, true)) {
            return;
        }

        $inserted = true;

        // A request concorrente cria o cliente e faz o commit dela.
        DB::table('customers')->insert([
            'tenant_id' => $this->tenant->id,
            'name' => 'João (concorrente)',
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/orders', orderPayload([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]))
        ->assertCreated();

    expect($inserted)->toBeTrue('o hook deveria ter simulado a corrida');

    // Um cliente só, e o pedido ficou vinculado a ele.
    actingAsTenant($this->tenant);
    expect(App\Models\Customer::where('phone', $phone)->count())->toBe(1);
    expect(Order::first()->customer->phone)->toBe($phone);
});

it('reaproveita o cliente já cadastrado em pedidos seguintes', function () {
    $payload = orderPayload([
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
    ]);

    $this->withHeader('X-Tenant', 'loja-a')->postJson('/api/orders', $payload)->assertCreated();
    $this->withHeader('X-Tenant', 'loja-a')->postJson('/api/orders', $payload)->assertCreated();

    actingAsTenant($this->tenant);

    expect(App\Models\Customer::where('phone', '11999998888')->count())->toBe(1)
        ->and(Order::count())->toBe(2);
});
