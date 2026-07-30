<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->user);
});

it('cria categoria já posicionada no fim da lista', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories', ['name' => 'Entradas'])
        ->assertCreated()
        ->assertJsonPath('position', 1);

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories', ['name' => 'Pratos'])
        ->assertCreated()
        ->assertJsonPath('position', 2);
});

it('gera slugs únicos para nomes repetidos', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories', ['name' => 'Bebidas'])
        ->assertCreated()
        ->assertJsonPath('slug', 'bebidas');

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories', ['name' => 'Bebidas'])
        ->assertCreated()
        ->assertJsonPath('slug', 'bebidas-2');
});

it('lista categorias com a contagem de produtos', function () {
    actingAsTenant($this->tenant);
    $category = Category::factory()->create(['name' => 'Pizzas']);
    Product::factory()->count(3)->create(['category_id' => $category->id]);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/categories')
        ->assertOk()
        ->assertJsonPath('data.0.products_count', 3);
});

it('impede excluir categoria que ainda tem produtos', function () {
    actingAsTenant($this->tenant);
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);
    forgetTenant();

    // Excluir em cascata aqui apagaria silenciosamente o cardápio do lojista.
    $this->withHeader('X-Tenant', 'loja-a')
        ->deleteJson("/api/admin/categories/{$category->id}")
        ->assertStatus(422);

    actingAsTenant($this->tenant);
    expect(Category::find($category->id))->not->toBeNull();
});

it('exclui categoria vazia', function () {
    actingAsTenant($this->tenant);
    $category = Category::factory()->create();
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->deleteJson("/api/admin/categories/{$category->id}")
        ->assertNoContent();
});

it('reordena categorias conforme a lista enviada', function () {
    actingAsTenant($this->tenant);
    $a = Category::factory()->create(['name' => 'A', 'position' => 0]);
    $b = Category::factory()->create(['name' => 'B', 'position' => 1]);
    $c = Category::factory()->create(['name' => 'C', 'position' => 2]);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories/reorder', ['ids' => [$c->id, $a->id, $b->id]])
        ->assertOk();

    actingAsTenant($this->tenant);
    expect(Category::orderBy('position')->pluck('name')->all())->toBe(['C', 'A', 'B']);
});

it('ignora ids de outro tenant na reordenação', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($other);
    $foreign = Category::factory()->create(['name' => 'Alheia', 'position' => 7]);

    actingAsTenant($this->tenant);
    $mine = Category::factory()->create(['name' => 'Minha', 'position' => 0]);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories/reorder', ['ids' => [$foreign->id, $mine->id]])
        ->assertOk();

    actingAsTenant($other);
    expect(Category::find($foreign->id)->position)->toBe(7);
});

it('não enxerga nem edita categoria de outra loja', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($other);
    $foreign = Category::factory()->create(['name' => 'Alheia']);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/categories')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('X-Tenant', 'loja-a')
        ->putJson("/api/admin/categories/{$foreign->id}", ['name' => 'Invadida'])
        ->assertNotFound();

    actingAsTenant($other);
    expect(Category::find($foreign->id)->name)->toBe('Alheia');
});

it('invalida o cache do cardápio ao mexer em categorias', function () {
    $before = $this->tenant->fresh()->menu_version;

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories', ['name' => 'Novidades'])
        ->assertCreated();

    expect($this->tenant->fresh()->menu_version)->toBeGreaterThan($before);
});
