<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * Plano "apenas cardápio digital".
 *
 * A loja publica o cardápio e nada mais: sem carrinho, sem checkout, sem
 * operação. A interface esconde tudo isso, mas o que estes testes protegem é a
 * camada abaixo — as rotas são públicas e adivinháveis, e esconder um botão
 * nunca foi um controle de acesso.
 */

beforeEach(function () {
    $this->menuPlan = Plan::factory()->menuOnly()->create(['slug' => 'cardapio']);
    $this->proPlan = Plan::factory()->create(['slug' => 'pro']);

    $this->vitrine = Tenant::factory()->create([
        'slug' => 'so-cardapio',
        'plan_id' => $this->menuPlan->id,
    ]);

    $this->completa = Tenant::factory()->create([
        'slug' => 'loja-pro',
        'plan_id' => $this->proPlan->id,
    ]);
});

/** Cardápio mínimo publicável para a loja informada. */
function seedMenuFor(Tenant $tenant): void
{
    actingAsTenant($tenant);

    TenantSettings::create(['tenant_id' => $tenant->id]);
    $category = Category::factory()->create(['is_active' => true]);
    Product::factory()->create([
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    forgetTenant();
}

// ---------------------------------------------------------------------------
// Storefront
// ---------------------------------------------------------------------------

it('publica o cardápio normalmente no plano vitrine', function () {
    seedMenuFor($this->vitrine);

    $this->withHeader('X-Tenant', 'so-cardapio')
        ->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.acceptsOrders', false)
        ->assertJsonCount(1, 'categories');
});

it('marca a loja Pro como recebedora de pedidos', function () {
    seedMenuFor($this->completa);

    $this->withHeader('X-Tenant', 'loja-pro')
        ->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.acceptsOrders', true);
});

it('recusa a criação de pedido na loja somente-cardápio', function () {
    seedMenuFor($this->vitrine);

    actingAsTenant($this->vitrine);
    $product = Product::query()->first();
    forgetTenant();

    // Payload íntegro de propósito: o que reprova aqui é o plano, não a
    // validação. Um corpo inválido faria o teste passar por 422 e não provaria
    // nada sobre a barreira.
    $this->withHeader('X-Tenant', 'so-cardapio')
        ->postJson('/api/orders', [
            'customer' => ['name' => 'Ana', 'phone' => '11999999999'],
            'fulfillment' => 'pickup',
            'paymentMethod' => 'cash',
            'items' => [['productId' => $product->id, 'quantity' => 1]],
        ])
        ->assertForbidden();

    expect($this->vitrine->orders()->count())->toBe(0);
});

it('recusa o acompanhamento de pedido na loja somente-cardápio', function () {
    seedMenuFor($this->vitrine);

    // Pedido real, criado direto no banco: a loja pode ter sido rebaixada de
    // plano e ainda carregar histórico. Com um id inexistente o route model
    // binding responderia 404 antes do middleware, e o teste passaria sem
    // provar que a barreira do plano existe.
    actingAsTenant($this->vitrine);
    $order = Order::create([
        'tenant_id' => $this->vitrine->id,
        'number' => 1,
        'status' => 'delivered',
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'subtotal_cents' => 1000,
        'delivery_fee_cents' => 0,
        'total_cents' => 1000,
        'placed_at' => now(),
    ]);
    forgetTenant();

    $this->withHeader('X-Tenant', 'so-cardapio')
        ->getJson("/api/orders/{$order->id}")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Painel do lojista
// ---------------------------------------------------------------------------

it('esconde as telas de operação e entrega do painel vitrine', function () {
    seedMenuFor($this->vitrine);

    $owner = User::factory()->create([
        'tenant_id' => $this->vitrine->id,
        'role' => 'owner',
    ]);

    Sanctum::actingAs($owner);
    $headers = ['X-Tenant' => 'so-cardapio'];

    $this->withHeaders($headers)->getJson('/api/admin/orders')->assertForbidden();
    $this->withHeaders($headers)->getJson('/api/admin/finance/overview')->assertForbidden();

    $this->withHeaders($headers)
        ->putJson('/api/admin/settings/delivery', [
            'feeCents' => 0,
            'minOrderCents' => 0,
            'etaMinutes' => 30,
        ])
        ->assertForbidden();
});

it('mantém cardápio, categorias e aparência no painel vitrine', function () {
    seedMenuFor($this->vitrine);

    $owner = User::factory()->create([
        'tenant_id' => $this->vitrine->id,
        'role' => 'owner',
    ]);

    Sanctum::actingAs($owner);
    $headers = ['X-Tenant' => 'so-cardapio'];

    $this->withHeaders($headers)->getJson('/api/admin/products')->assertOk();
    $this->withHeaders($headers)->getJson('/api/admin/categories')->assertOk();

    $this->withHeaders($headers)
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('plan.slug', 'cardapio')
        ->assertJsonPath('plan.allowsOrders', false)
        ->assertJsonPath('plan.allowsDelivery', false);
});

it('não afeta a loja Pro', function () {
    seedMenuFor($this->completa);

    $owner = User::factory()->create([
        'tenant_id' => $this->completa->id,
        'role' => 'owner',
    ]);

    Sanctum::actingAs($owner);
    $headers = ['X-Tenant' => 'loja-pro'];

    $this->withHeaders($headers)->getJson('/api/admin/orders')->assertOk();
    $this->withHeaders($headers)->getJson('/api/admin/finance/overview')->assertOk();

    $this->withHeaders($headers)
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('plan.allowsOrders', true);
});

// ---------------------------------------------------------------------------
// Cadastro
// ---------------------------------------------------------------------------

it('cria a loja no plano escolhido no cadastro', function () {
    $this->postJson('/api/register', [
        'store_name' => 'Cantina da Nona',
        'slug' => 'cantina-da-nona',
        'owner_name' => 'Ana Souza',
        'email' => 'ana@cantina.test',
        'password' => 'segredo123',
        'password_confirmation' => 'segredo123',
        'plan' => 'cardapio',
        'accepted_terms' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('tenant.plan', 'cardapio');

    $tenant = Tenant::where('slug', 'cantina-da-nona')->firstOrFail();

    expect($tenant->allowsOrders())->toBeFalse()
        ->and($tenant->isMenuOnly())->toBeTrue();
});

it('cria no Pro quando o cadastro não informa plano', function () {
    $this->postJson('/api/register', [
        'store_name' => 'Forno Velho',
        'slug' => 'forno-velho',
        'owner_name' => 'Bruno Lima',
        'email' => 'bruno@forno.test',
        'password' => 'segredo123',
        'password_confirmation' => 'segredo123',
        'accepted_terms' => true,
    ])->assertCreated();

    $tenant = Tenant::where('slug', 'forno-velho')->firstOrFail();

    expect($tenant->allowsOrders())->toBeTrue();
});

it('recusa um plano que não é contratável', function () {
    $this->postJson('/api/register', [
        'store_name' => 'Loja Fantasma',
        'slug' => 'loja-fantasma',
        'owner_name' => 'Caio Reis',
        'email' => 'caio@fantasma.test',
        'password' => 'segredo123',
        'password_confirmation' => 'segredo123',
        'plan' => 'enterprise-interno',
        'accepted_terms' => true,
    ])->assertJsonValidationErrors('plan');

    expect(Tenant::where('slug', 'loja-fantasma')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Definição dos planos
// ---------------------------------------------------------------------------

it('semeia os dois planos com as capacidades anunciadas', function () {
    // O seeder usa updateOrCreate: as linhas criadas no beforeEach são
    // corrigidas para a definição canônica em vez de duplicadas.
    (new PlanSeeder)->run();

    $menu = Plan::where('slug', Plan::MENU_ONLY)->firstOrFail();
    $pro = Plan::where('slug', Plan::PRO)->firstOrFail();

    expect($menu->allows_orders)->toBeFalse()
        ->and($menu->allows_delivery)->toBeFalse()
        ->and($menu->allows_online_payment)->toBeFalse()
        ->and($menu->isMenuOnly())->toBeTrue()
        ->and($pro->allows_orders)->toBeTrue()
        ->and($pro->allows_online_payment)->toBeTrue()
        ->and($pro->isMenuOnly())->toBeFalse();
});

it('não oferece pagamento online mesmo com o Stripe conectado no plano vitrine', function () {
    // As duas condições são independentes: o Connect pode estar concluído (uma
    // loja rebaixada de plano, por exemplo) e ainda assim o checkout online não
    // deve existir, porque não há checkout nenhum.
    $this->vitrine->update([
        'stripe_account_id' => 'acct_teste',
        'stripe_charges_enabled' => true,
    ]);

    expect($this->vitrine->fresh()->acceptsOnlinePayment())->toBeFalse();
});
