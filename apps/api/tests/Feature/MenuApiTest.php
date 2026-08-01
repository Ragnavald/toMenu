<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'loja-b']);
});

it('devolve apenas o cardápio do tenant identificado', function () {
    actingAsTenant($this->tenantA);
    $categoryA = Category::factory()->create(['name' => 'Pizzas A']);
    Product::factory()->create(['category_id' => $categoryA->id, 'name' => 'Margherita A']);

    actingAsTenant($this->tenantB);
    $categoryB = Category::factory()->create(['name' => 'Pizzas B']);
    Product::factory()->create(['category_id' => $categoryB->id, 'name' => 'Margherita B']);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    $response->assertOk()
        ->assertJsonPath('tenant.slug', 'loja-a')
        ->assertJsonPath('categories.0.products.0.name', 'Margherita A');

    expect(json_encode($response->json()))->not->toContain('Margherita B');
});

it('omite do cardápio público as seções sem itens disponíveis', function () {
    actingAsTenant($this->tenantA);

    $vazia = Category::factory()->create(['name' => 'Seção Vazia']);
    $comItem = Category::factory()->create(['name' => 'Com Itens']);
    Product::factory()->create(['category_id' => $comItem->id, 'name' => 'Prato']);

    // Seção cujo único item está esgotado também não deve aparecer.
    $esgotada = Category::factory()->create(['name' => 'Só Esgotados']);
    Product::factory()->create([
        'category_id' => $esgotada->id,
        'is_available' => false,
    ]);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    $response->assertOk()->assertJsonCount(1, 'categories');

    expect($response->json('categories.0.name'))->toBe('Com Itens')
        ->and(json_encode($response->json()))
        ->not->toContain('Seção Vazia')
        ->not->toContain('Só Esgotados');
});

it('retorna 404 para tenant inexistente', function () {
    $this->withHeader('X-Tenant', 'nao-existe')
        ->getJson('/api/menu')
        ->assertNotFound();
});

it('bloqueia loja suspensa', function () {
    $this->tenantA->update(['status' => 'suspended']);

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/menu')
        ->assertForbidden();
});

it('envia headers de cache e ETag', function () {
    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('s-maxage=60')
        ->and($response->headers->get('Cache-Control'))->toContain('stale-while-revalidate')
        ->and($response->headers->get('ETag'))->not->toBeNull();
});

it('resolve o tenant corretamente na segunda request, já com cache quente', function () {
    // Regressão: a resolução cacheava o model Eloquent serializado, e a leitura
    // seguinte devolvia __PHP_Incomplete_Class, derrubando o endpoint com 500.
    // O cache agora guarda apenas o ID.
    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')->assertOk();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.slug', 'loja-a');
});

it('mantém arrays JSON no payload servido a partir do cache', function () {
    // Regressão: o service devolvia Collections aninhadas. Recém-construído o
    // JSON saía como array, mas ao passar pelo cache voltava como objeto
    // tipado e o cliente quebrava ao iterar sobre categories/modifierGroups.
    actingAsTenant($this->tenantA);
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    $group = App\Models\ModifierGroup::create([
        'name' => 'Tamanho',
        'min_select' => 1,
        'max_select' => 1,
        'is_required' => true,
    ]);
    App\Models\Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Grande',
        'price_delta_cents' => 500,
    ]);
    $product->modifierGroups()->attach($group->id, ['position' => 0]);
    forgetTenant();

    // Primeira request popula o cache; a segunda lê de lá.
    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')->assertOk();
    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    // Índices numéricos comprovam array JSON; um objeto quebraria estes paths.
    $response->assertOk()
        ->assertJsonPath('categories.0.products.0.modifierGroups.0.name', 'Tamanho')
        ->assertJsonPath('categories.0.products.0.modifierGroups.0.modifiers.0.name', 'Grande');

    expect($response->json('categories'))->toBeArray()
        ->and($response->json('categories.0.products'))->toBeArray()
        ->and($response->json('categories.0.products.0.modifierGroups'))->toBeArray();
});

it('responde 304 quando o ETag continua válido', function () {
    $first = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');
    $etag = $first->headers->get('ETag');

    $this->withHeaders(['X-Tenant' => 'loja-a', 'If-None-Match' => $etag])
        ->get('/api/menu')
        ->assertStatus(304);
});

it('muda o ETag quando o cardápio é alterado', function () {
    $before = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')
        ->headers->get('ETag');

    actingAsTenant($this->tenantA);
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);
    forgetTenant();

    $after = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')
        ->headers->get('ETag');

    expect($after)->not->toBe($before);
});

it('não expõe dados sensíveis do tenant no payload público', function () {
    $this->tenantA->update(['stripe_customer_id' => 'cus_segredo_do_tenant']);

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    expect(json_encode($response->json()))
        ->not->toContain('cus_segredo_do_tenant')
        ->not->toContain('stripe_customer_id');
});

it('sanitiza tema malicioso antes de servir ao storefront', function () {
    // O tema é injetado dentro de <style> no SSR. Um valor livre aqui seria
    // CSS injection contra todos os visitantes da loja.
    TenantSettings::create([
        'tenant_id' => $this->tenantA->id,
        'theme' => [
            'brand' => '0 0 0; } body { background: url(https://evil.test/?c=1); a {',
            'font' => '../../etc/passwd',
        ],
    ]);

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    expect($response->json('theme.brand'))->toBe('234 88 12')   // fallback
        ->and($response->json('theme.font'))->toBe('inter');    // fallback
});
