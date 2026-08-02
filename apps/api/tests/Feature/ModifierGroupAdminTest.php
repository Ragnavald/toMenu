<?php

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->user);

    actingAsTenant($this->tenant);
    $this->flavours = Category::factory()->create(['name' => 'Sabores']);
    $this->margherita = Product::factory()->create([
        'category_id' => $this->flavours->id,
        'name' => 'Margherita',
        'price_cents' => 5900,
    ]);
    $this->diavola = Product::factory()->create([
        'category_id' => $this->flavours->id,
        'name' => 'Diavola',
        'price_cents' => 6700,
    ]);
    forgetTenant();
});

it('cria grupo composto apontando para a categoria de sabores', function () {
    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/modifier-groups', [
            'name' => 'Escolha 2 sabores',
            'min_select' => 2,
            'max_select' => 2,
            'is_required' => true,
            'source' => 'category',
            'source_category_id' => $this->flavours->id,
            'pricing_rule' => 'highest',
            'options' => [
                ['product_id' => $this->margherita->id, 'price_cents' => 7100],
                // Sem override: vale o preço do próprio sabor.
                ['product_id' => $this->diavola->id],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('source', 'category')
        ->assertJsonPath('pricing_rule', 'highest');

    actingAsTenant($this->tenant);

    $group = ModifierGroup::with('optionProducts')->latest('id')->first();

    // O override de preço é o que faz a pizza família custar mais que a média
    // usando o mesmo sabor.
    expect($group->optionProducts)->toHaveCount(2)
        ->and($group->optionProducts->firstWhere('id', $this->margherita->id)->pivot->price_cents)->toBe(7100)
        ->and($group->optionProducts->firstWhere('id', $this->diavola->id)->pivot->price_cents)->toBeNull();
});

it('cria grupo de lista com os modificadores enviados', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/modifier-groups', [
            'name' => 'Borda',
            'min_select' => 0,
            'max_select' => 1,
            'source' => 'list',
            'pricing_rule' => 'sum',
            'modifiers' => [
                ['name' => 'Catupiry', 'price_delta_cents' => 900],
                ['name' => 'Cheddar', 'price_delta_cents' => 900],
            ],
        ])
        ->assertCreated();

    actingAsTenant($this->tenant);

    expect(ModifierGroup::latest('id')->first()->modifiers)->toHaveCount(2);
});

it('exige a categoria de origem no grupo composto', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/modifier-groups', [
            'name' => 'Sabores',
            'min_select' => 1,
            'max_select' => 2,
            'source' => 'category',
            'pricing_rule' => 'highest',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_category_id');
});

it('recusa exigir mais escolhas do que existem opções', function () {
    // Grupo pedindo 3 sabores com 1 cadastrado travaria o checkout: nenhum
    // pedido daquele produto passaria pelo OrderService.
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/modifier-groups', [
            'name' => 'Escolha 3 sabores',
            'min_select' => 3,
            'max_select' => 3,
            'source' => 'category',
            'source_category_id' => $this->flavours->id,
            'pricing_rule' => 'highest',
            'options' => [['product_id' => $this->margherita->id]],
        ])
        ->assertStatus(422);
});

it('recusa max_select menor que min_select', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/modifier-groups', [
            'name' => 'Incoerente',
            'min_select' => 3,
            'max_select' => 1,
            'source' => 'list',
            'pricing_rule' => 'sum',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('max_select');
});

it('não aceita categoria de outra loja como origem', function () {
    $outra = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($outra);
    $categoriaAlheia = Category::factory()->create(['name' => 'Sabores B']);
    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/modifier-groups', [
            'name' => 'Sabores',
            'min_select' => 1,
            'max_select' => 1,
            'source' => 'category',
            'source_category_id' => $categoriaAlheia->id,
            'pricing_rule' => 'highest',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source_category_id');
});

it('limpa os modificadores ao converter grupo de lista em composto', function () {
    actingAsTenant($this->tenant);

    $group = ModifierGroup::create([
        'name' => 'Tamanho',
        'min_select' => 1,
        'max_select' => 1,
        'is_required' => true,
    ]);

    Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Grande',
        'price_delta_cents' => 1200,
    ]);

    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->putJson("/api/admin/modifier-groups/{$group->id}", [
            'name' => 'Sabores',
            'min_select' => 1,
            'max_select' => 2,
            'source' => 'category',
            'source_category_id' => $this->flavours->id,
            'pricing_rule' => 'highest',
            'options' => [['product_id' => $this->margherita->id]],
        ])
        ->assertOk();

    actingAsTenant($this->tenant);

    // Os modifiers antigos não podem sobreviver escondidos: se a origem
    // voltasse para 'list', eles reapareceriam no cardápio sem aviso.
    expect($group->fresh()->modifiers)->toHaveCount(0)
        ->and($group->fresh()->optionProducts)->toHaveCount(1);
});

it('recusa excluir grupo vinculado a produtos', function () {
    actingAsTenant($this->tenant);

    $group = ModifierGroup::create([
        'name' => 'Borda',
        'min_select' => 0,
        'max_select' => 1,
    ]);

    $pizza = Product::factory()->create(['category_id' => $this->flavours->id]);
    $pizza->modifierGroups()->attach($group->id, ['position' => 0]);

    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->deleteJson("/api/admin/modifier-groups/{$group->id}")
        ->assertStatus(422);
});

it('vincula o grupo a vários produtos de uma vez', function () {
    actingAsTenant($this->tenant);

    $group = ModifierGroup::create([
        'name' => 'Borda',
        'min_select' => 0,
        'max_select' => 1,
    ]);

    $media = Product::factory()->create(['category_id' => $this->flavours->id]);
    $grande = Product::factory()->create(['category_id' => $this->flavours->id]);

    forgetTenant();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson("/api/admin/modifier-groups/{$group->id}/products", [
            'product_ids' => [$media->id, $grande->id],
        ])
        ->assertOk();

    actingAsTenant($this->tenant);

    // O ponto do grupo compartilhado: a borda é editada uma vez e vale para
    // todos os tamanhos.
    expect($group->fresh()->products)->toHaveCount(2);
});

it('não enxerga grupos de outra loja', function () {
    $outra = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($outra);
    ModifierGroup::create(['name' => 'Borda da Loja B', 'min_select' => 0, 'max_select' => 1]);
    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/modifier-groups');

    $response->assertOk();

    expect(json_encode($response->json()))->not->toContain('Borda da Loja B');
});

it('marca a categoria como somente-opção', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories', [
            'name' => 'Sabores de Pizza',
            'is_option_only' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('is_option_only', true);
});
