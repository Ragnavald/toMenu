<?php

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;

/**
 * Subcategorias compostas — o cenário da pizza meio a meio.
 *
 * O que precisa ser defendido aqui é a precificação: o formato custa zero e
 * todo o valor vem dos sabores escolhidos, resolvidos no servidor. Um erro de
 * regra não quebra nada visivelmente — só cobra o valor errado, em silêncio.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'pizzaria']);

    actingAsTenant($this->tenant);

    TenantSettings::create([
        'tenant_id' => $this->tenant->id,
        'payment_methods' => ['cash'],
        'delivery_config' => ['fee_cents' => 0, 'accepts_pickup' => true],
    ]);

    $this->flavourCategory = Category::factory()->create([
        'name' => 'Sabores',
        'is_option_only' => true,
    ]);

    $this->margherita = Product::factory()->create([
        'category_id' => $this->flavourCategory->id,
        'name' => 'Margherita',
        'price_cents' => 5900,
    ]);

    $this->prosciutto = Product::factory()->create([
        'category_id' => $this->flavourCategory->id,
        'name' => 'Prosciutto',
        'price_cents' => 7200,
    ]);

    $this->pizzas = Category::factory()->create(['name' => 'Pizzas']);
});

/** Monta um formato de pizza com um grupo composto de sabores. */
function pizzaFormat(array $ctx, string $name, int $min, int $max, string $rule = 'highest'): array
{
    $group = ModifierGroup::create([
        'name' => "Escolha {$max}",
        'min_select' => $min,
        'max_select' => $max,
        'is_required' => true,
        'source' => ModifierGroup::SOURCE_CATEGORY,
        'source_category_id' => $ctx['flavourCategory']->id,
        'pricing_rule' => $rule,
    ]);

    foreach ([$ctx['margherita'], $ctx['prosciutto']] as $i => $flavour) {
        $group->optionProducts()->attach($flavour->id, [
            'tenant_id' => $ctx['tenant']->id,
            'position' => $i,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $product = Product::factory()->create([
        'category_id' => $ctx['pizzas']->id,
        'name' => $name,
        'price_cents' => 0,
    ]);

    $product->modifierGroups()->attach($group->id, ['position' => 0]);

    return [$product, $group];
}

it('esconde do cardápio a categoria que só abastece grupos compostos', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Pizza Grande', 1, 1);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->getJson('/api/menu');

    $response->assertOk()->assertJsonCount(1, 'categories');

    expect($response->json('categories.0.name'))->toBe('Pizzas');

    // Os sabores continuam presentes como opções dentro da pizza — o que some
    // é a seção vendável, não o dado.
    $flavours = collect($response->json('categories.0.products.0.modifierGroups.0.modifiers'))
        ->pluck('name');

    expect($flavours)->toContain('Margherita', 'Prosciutto');
});

it('cobra o sabor mais caro na pizza meio a meio', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Meio a Meio', 2, 2);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id, $this->prosciutto->id],
        ]],
    ]);

    $response->assertCreated();

    // 5900 e 7200 escolhidos: soma daria 13100 e cobraria duas pizzas.
    expect($response->json('totalCents'))->toBe(7200);
});

it('aplica a média quando a loja configura a regra assim', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Meio a Meio', 2, 2, rule: 'average');

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id, $this->prosciutto->id],
        ]],
    ]);

    $response->assertCreated();

    expect($response->json('totalCents'))->toBe(6550); // (5900 + 7200) / 2
});

it('soma a borda ao sabor mais caro', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Meio a Meio', 2, 2);

    // Grupo de lista convive com o composto no mesmo produto: cada um aplica a
    // sua regra, e é essa combinação que o cliente final espera.
    $crust = ModifierGroup::create([
        'name' => 'Borda',
        'min_select' => 0,
        'max_select' => 1,
        'is_required' => false,
    ]);

    $catupiry = Modifier::create([
        'modifier_group_id' => $crust->id,
        'name' => 'Catupiry',
        'price_delta_cents' => 900,
    ]);

    $pizza->modifierGroups()->attach($crust->id, ['position' => 1]);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id, $this->prosciutto->id, $catupiry->id],
        ]],
    ]);

    $response->assertCreated();

    expect($response->json('totalCents'))->toBe(8100); // 7200 (maior sabor) + 900
});

it('usa o preço da pivot quando o formato tem acréscimo', function () {
    [$pizza, $group] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Pizza Família', 1, 1);

    // A família cobra R$12 a mais por sabor que a média.
    $group->optionProducts()->updateExistingPivot($this->margherita->id, [
        'price_cents' => 7100,
    ]);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id],
        ]],
    ]);

    $response->assertCreated();

    expect($response->json('totalCents'))->toBe(7100); // não 5900
});

it('recusa o pedido sem os sabores obrigatórios', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Meio a Meio', 2, 2);

    forgetTenant();

    // Só um sabor num grupo que exige dois. O storefront não deixaria enviar,
    // mas o POST é público.
    $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id],
        ]],
    ])->assertStatus(422);
});

it('recusa mais sabores do que o formato permite', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Pizza Grande', 1, 1);

    forgetTenant();

    // Dois sabores num formato de um só. Com a regra 'highest', aceitar isso
    // entregaria duas metades pelo preço de uma.
    $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id, $this->prosciutto->id],
        ]],
    ])->assertStatus(422);
});

it('recusa sabor que não pertence ao grupo do produto', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Pizza Grande', 1, 1);

    // Produto avulso, jamais ofertado como sabor deste grupo.
    $intruso = Product::factory()->create([
        'category_id' => $this->pizzas->id,
        'name' => 'Refrigerante',
        'price_cents' => 800,
    ]);

    forgetTenant();

    // O ID existe e é do mesmo tenant — o que o barra é não pertencer a nenhum
    // grupo deste produto.
    $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id, $intruso->id],
        ]],
    ])->assertStatus(422);
});

it('não oferta sabor esgotado', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Pizza Grande', 1, 1);

    $this->prosciutto->update(['is_available' => false]);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->getJson('/api/menu');

    $flavours = collect($response->json('categories.0.products.0.modifierGroups.0.modifiers'))
        ->pluck('name');

    expect($flavours)->toContain('Margherita')->not->toContain('Prosciutto');

    // E o que sumiu da vitrine também não é aceito no pedido.
    $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->prosciutto->id],
        ]],
    ])->assertStatus(422);
});

it('registra o grupo de cada opção no snapshot do pedido', function () {
    [$pizza] = pizzaFormat([
        'flavourCategory' => $this->flavourCategory,
        'margherita' => $this->margherita,
        'prosciutto' => $this->prosciutto,
        'tenant' => $this->tenant,
        'pizzas' => $this->pizzas,
    ], 'Meio a Meio', 2, 2);

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => [$this->margherita->id, $this->prosciutto->id],
        ]],
    ]);

    $response->assertCreated();

    /*
     * A asserção é sobre o registro gravado, não sobre a resposta: nem o POST
     * nem o GET de acompanhamento devolvem os modificadores hoje. Quem lê este
     * snapshot é o painel da cozinha, e ele lê do banco.
     *
     * O nome do grupo importa porque o pedido é impresso: sem ele, dois sabores
     * e uma borda saem como três linhas soltas e o pizzaiolo não sabe qual é
     * metade e qual é acréscimo.
     */
    actingAsTenant($this->tenant);

    $snapshot = collect(
        \App\Models\Order::latest('id')->first()->items->first()->modifiers_snapshot
    );

    expect($snapshot)->toHaveCount(2)
        ->and($snapshot->pluck('name')->all())->toBe(['Margherita', 'Prosciutto'])
        ->and($snapshot->pluck('groupName')->unique()->all())->toBe(['Escolha 2']);
});
