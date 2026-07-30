<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'loja-b']);

    $this->userA = User::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'email' => 'dono@loja-a.test',
        'role' => 'owner',
    ]);
});

it('exige autenticação nas rotas de admin', function () {
    // 401 em JSON, não redirect para uma rota `login` inexistente.
    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/products')
        ->assertUnauthorized();
});

it('responde 401 mesmo sem o header Accept', function () {
    // Regressão: o middleware Authenticate tentava route('login') e devolvia
    // 500. O bug só aparecia sem `Accept: application/json`, então getJson()
    // sozinho não o detectava — este teste usa `get()` de propósito.
    $this->withHeader('X-Tenant', 'loja-a')
        ->get('/api/admin/products')
        ->assertUnauthorized();
});

it('impede que o dono de uma loja acesse o admin de outra', function () {
    Sanctum::actingAs($this->userA);

    // Token legítimo, mas apontando para a loja alheia: o middleware
    // tenant.member cruza o tenant do usuário com o do contexto.
    $this->withHeader('X-Tenant', 'loja-b')
        ->getJson('/api/admin/products')
        ->assertForbidden();
});

it('lista somente os produtos da própria loja', function () {
    actingAsTenant($this->tenantB);
    $categoryB = Category::factory()->create();
    Product::factory()->create(['category_id' => $categoryB->id, 'name' => 'Item de B']);

    actingAsTenant($this->tenantA);
    $categoryA = Category::factory()->create();
    Product::factory()->create(['category_id' => $categoryA->id, 'name' => 'Item de A']);
    forgetTenant();

    Sanctum::actingAs($this->userA);

    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/products');

    $response->assertOk()->assertJsonPath('total', 1);
    expect(json_encode($response->json()))->not->toContain('Item de B');
});

it('impede editar produto de outro tenant', function () {
    actingAsTenant($this->tenantB);
    $categoryB = Category::factory()->create();
    $productOfB = Product::factory()->create([
        'category_id' => $categoryB->id,
        'name' => 'Original',
    ]);

    // Categoria própria, para isolar a checagem no produto e não na categoria.
    actingAsTenant($this->tenantA);
    $categoryA = Category::factory()->create();
    forgetTenant();

    Sanctum::actingAs($this->userA);

    // Com um category_id válido no próprio tenant, a validação passa e sobra
    // o route model binding, que aplica o global scope e devolve 404.
    $this->withHeader('X-Tenant', 'loja-a')
        ->putJson("/api/admin/products/{$productOfB->id}", [
            'category_id' => $categoryA->id,
            'name' => 'Invadido',
            'price_cents' => 100,
        ])
        ->assertNotFound();

    actingAsTenant($this->tenantB);
    expect(Product::find($productOfB->id)->name)->toBe('Original');
});

it('nunca confirma uma escrita que o banco recusou', function () {
    // Regressão: o binding resolvia o produto alheio (o contexto de tenant
    // ainda não existia em substituteBindings), o RLS bloqueava a escrita, mas
    // a API respondia 200 com o objeto alterado em memória — confirmação falsa
    // de uma alteração que nunca aconteceu.
    actingAsTenant($this->tenantB);
    $categoryB = Category::factory()->create();
    $productOfB = Product::factory()->create([
        'category_id' => $categoryB->id,
        'name' => 'Original',
    ]);

    actingAsTenant($this->tenantA);
    $categoryA = Category::factory()->create();
    forgetTenant();

    Sanctum::actingAs($this->userA);

    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->putJson("/api/admin/products/{$productOfB->id}", [
            'category_id' => $categoryA->id,
            'name' => 'Invadido',
            'price_cents' => 100,
        ]);

    expect($response->status())->not->toBe(200);

    actingAsTenant($this->tenantB);
    expect(Product::find($productOfB->id)->name)->toBe('Original');
});

it('impede remover produto de outro tenant', function () {
    actingAsTenant($this->tenantB);
    $categoryB = Category::factory()->create();
    $productOfB = Product::factory()->create(['category_id' => $categoryB->id]);
    forgetTenant();

    Sanctum::actingAs($this->userA);

    $this->withHeader('X-Tenant', 'loja-a')
        ->deleteJson("/api/admin/products/{$productOfB->id}")
        ->assertNotFound();

    actingAsTenant($this->tenantB);
    expect(Product::find($productOfB->id))->not->toBeNull();
});

it('recusa editar produto usando categoria de outro tenant', function () {
    actingAsTenant($this->tenantB);
    $categoryB = Category::factory()->create();
    $productOfB = Product::factory()->create(['category_id' => $categoryB->id]);
    forgetTenant();

    Sanctum::actingAs($this->userA);

    // Aqui a validação de category_id barra antes do binding: 422, não 404.
    // Ambos os caminhos bloqueiam — o que importa é que nada é alterado.
    $this->withHeader('X-Tenant', 'loja-a')
        ->putJson("/api/admin/products/{$productOfB->id}", [
            'category_id' => $categoryB->id,
            'name' => 'Invadido',
            'price_cents' => 100,
        ])
        ->assertStatus(422);
});

it('recusa produto vinculado a categoria de outro tenant', function () {
    actingAsTenant($this->tenantB);
    $categoryOfB = Category::factory()->create();
    forgetTenant();

    Sanctum::actingAs($this->userA);

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/products', [
            'category_id' => $categoryOfB->id,
            'name' => 'Produto',
            'price_cents' => 1000,
        ])
        ->assertStatus(422);
});

it('invalida o cache do cardápio ao salvar um produto', function () {
    actingAsTenant($this->tenantA);
    $category = Category::factory()->create();
    forgetTenant();

    $before = $this->tenantA->fresh()->menu_version;

    Sanctum::actingAs($this->userA);
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Novidade',
            'price_cents' => 2500,
        ])
        ->assertCreated();

    // Sem o bump, a loja continuaria servindo o cardápio antigo até o TTL.
    expect($this->tenantA->fresh()->menu_version)->toBeGreaterThan($before);
});

it('sanitiza o tema recebido do painel antes de gravar', function () {
    Sanctum::actingAs($this->userA);

    $this->withHeader('X-Tenant', 'loja-a')
        ->putJson('/api/admin/settings/theme', [
            'theme' => [
                'brand' => '</style><script>alert(1)</script>',
                'radius' => '9999px',
                'layout' => 'inexistente',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('theme.brand', '234 88 12')
        ->assertJsonPath('theme.radius', '16px')
        ->assertJsonPath('theme.layout', 'classic');
});
