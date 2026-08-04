<?php

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use App\Services\ModifierOptionResolver;

/**
 * Import de modelo de cardápio.
 *
 * O que precisa ser defendido aqui é a ligação entre as três entidades. Criar
 * as categorias e os produtos é a parte visível; o que quebra em silêncio é o
 * produto de dois sabores nascer sem o grupo composto — ele continua no
 * cardápio, vendável, e deixa o cliente pedir pizza sem escolher sabor.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'pizzaria']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    actingAsTenant($this->tenant);
});

/** O payload que o painel manda ao importar o modelo de pizzaria. */
function pizzaImportPayload(array $overrides = []): array
{
    return array_merge([
        'categories' => [
            ['ref' => 'pizzas', 'name' => '🍕 Pizzas Salgadas'],
            ['ref' => 'sabores', 'name' => '🧀 Sabores de Pizza', 'is_option_only' => true],
        ],
        'groups' => [
            [
                'ref' => 'dois_sabores',
                'name' => 'Escolha 2 sabores',
                'min_select' => 2,
                'max_select' => 2,
                'is_required' => true,
                'source' => 'category',
                'source_category_ref' => 'sabores',
                'pricing_rule' => 'highest',
            ],
        ],
        'products' => [
            [
                'ref' => 'grande',
                'category_ref' => 'pizzas',
                'name' => 'Pizza Grande',
                'price_cents' => 6900,
                'group_refs' => [],
            ],
            [
                'ref' => 'grande_2',
                'category_ref' => 'pizzas',
                'name' => 'Pizza Grande 2 Sabores',
                'price_cents' => 6900,
                'group_refs' => ['dois_sabores'],
            ],
            [
                'ref' => 'sabor_1',
                'category_ref' => 'sabores',
                'name' => 'Sabor 1',
                'price_cents' => 0,
                'group_refs' => [],
            ],
        ],
    ], $overrides);
}

it('cria categorias, produtos e grupos numa chamada só', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated()
        ->assertJsonPath('data.categories_created', 2)
        ->assertJsonPath('data.products_created', 3)
        ->assertJsonPath('data.groups_created', 1);

    expect(Category::count())->toBe(2)
        ->and(Product::count())->toBe(3)
        ->and(ModifierGroup::count())->toBe(1);
});

it('aponta o grupo composto para a categoria de sabores criada no mesmo import', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    $flavours = Category::where('name', '🧀 Sabores de Pizza')->sole();
    $group = ModifierGroup::sole();

    expect($flavours->is_option_only)->toBeTrue()
        ->and($group->source)->toBe(ModifierGroup::SOURCE_CATEGORY)
        ->and($group->source_category_id)->toBe($flavours->id)
        ->and($group->pricing_rule)->toBe('highest');
});

it('vincula o grupo de sabores só aos produtos de dois sabores', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    $doisSabores = Product::where('name', 'Pizza Grande 2 Sabores')->sole();
    $umSabor = Product::where('name', 'Pizza Grande')->sole();

    expect($doisSabores->modifierGroups)->toHaveCount(1)
        ->and($doisSabores->modifierGroups->first()->name)->toBe('Escolha 2 sabores')
        ->and($umSabor->modifierGroups)->toHaveCount(0);
});

it('deixa os sabores genéricos como produtos da categoria-insumo', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    $flavours = Category::where('is_option_only', true)->sole();

    // Os sabores são produtos de verdade, não modifiers digitados dentro do
    // grupo: é o que permite renomear num lugar só e valer para todo tamanho.
    expect(Product::where('category_id', $flavours->id)->pluck('name')->all())
        ->toBe(['Sabor 1']);
});

/**
 * O `source_category_id` sozinho não oferta sabor nenhum.
 *
 * Quem o cardápio e o pedido leem é a pivot `modifier_group_product`, via
 * ModifierOptionResolver. Um grupo composto que só aponta a categoria de origem
 * nasce com zero opções — e com min_select 2 o produto vira invendável: o
 * OrderService barra o pedido por "escolha as opções obrigatórias" e não há
 * escolha possível. É o oposto do meio a meio que o modelo promete entregar.
 */
it('oferta os sabores da categoria de origem como opções do grupo composto', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    $options = app(ModifierOptionResolver::class)->options(ModifierGroup::sole());

    expect($options->pluck('name')->all())->toBe(['Sabor 1']);
});

/**
 * O modelo real da pizzaria manda dois sabores porque o grupo exige dois.
 *
 * Um grupo com min_select 2 e uma opção só é invendável na prática: o cliente
 * não consegue completar a escolha e o OrderService recusa o pedido. O modelo
 * precisa nascer com opções suficientes para o próprio mínimo que declara.
 */
it('importa sabores suficientes para o mínimo que o grupo exige', function () {
    $payload = pizzaImportPayload();
    $payload['products'][] = [
        'ref' => 'sabor_2',
        'category_ref' => 'sabores',
        'name' => 'Sabor 2',
        'price_cents' => 0,
        'group_refs' => [],
    ];

    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', $payload)
        ->assertCreated();

    $group = ModifierGroup::sole();
    $options = app(ModifierOptionResolver::class)->options($group);

    expect($options->pluck('name')->all())->toBe(['Sabor 1', 'Sabor 2'])
        ->and($options->count())->toBeGreaterThanOrEqual($group->min_select);
});

it('não oferta como sabor o produto de outra categoria', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    // "Pizza Grande" está em `pizzas`, não na categoria-insumo: escolher um
    // tamanho como se fosse sabor cobraria a pizza inteira dentro da pizza.
    expect(app(ModifierOptionResolver::class)->options(ModifierGroup::sole())->pluck('name'))
        ->not->toContain('Pizza Grande');
});

/**
 * A prova final do import: o que ele cria dá para vender.
 *
 * Os testes acima olham a estrutura; este olha o resultado. Um grupo composto
 * sem opções passa em toda verificação de estrutura e só falha aqui, no POST
 * do pedido — que é onde o lojista descobriria, pelo cliente que desistiu.
 */
it('deixa o produto de dois sabores pedível logo após o import', function () {
    TenantSettings::create([
        'tenant_id' => $this->tenant->id,
        'payment_methods' => ['cash'],
        'delivery_config' => ['fee_cents' => 0, 'accepts_pickup' => true],
    ]);

    $payload = pizzaImportPayload();
    // O formato custa zero e o preço vem do sabor, como no modelo real: um
    // formato pago somaria o sabor por cima e cobraria quase duas pizzas.
    $payload['products'][1]['price_cents'] = 0;
    // Preços distintos para a regra 'highest' ficar observável no total.
    $payload['products'][2]['price_cents'] = 5900;
    $payload['products'][] = [
        'ref' => 'sabor_2',
        'category_ref' => 'sabores',
        'name' => 'Sabor 2',
        'price_cents' => 7200,
        'group_refs' => [],
    ];

    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', $payload)
        ->assertCreated();

    $pizza = Product::where('name', 'Pizza Grande 2 Sabores')->sole();
    $flavours = app(ModifierOptionResolver::class)->options(ModifierGroup::sole());

    forgetTenant();

    $response = $this->withHeader('X-Tenant', 'pizzaria')->postJson('/api/orders', [
        'customer' => ['name' => 'Cliente', 'phone' => '11999999999'],
        'fulfillment' => 'pickup',
        'payment_method' => 'cash',
        'items' => [[
            'product_id' => $pizza->id,
            'quantity' => 1,
            'modifier_ids' => $flavours->pluck('id')->all(),
        ]],
    ]);

    // 'highest': somar 5900 e 7200 cobraria duas pizzas.
    $response->assertCreated();
    expect($response->json('totalCents'))->toBe(7200);
});

it('recusa produto que aponta para categoria fora do payload', function () {
    $payload = pizzaImportPayload();
    $payload['products'][0]['category_ref'] = 'inexistente';

    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', $payload)
        ->assertStatus(422);
});

it('recusa grupo composto sem categoria de origem', function () {
    $payload = pizzaImportPayload();
    unset($payload['groups'][0]['source_category_ref']);

    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', $payload)
        ->assertStatus(422);
});

it('não deixa cardápio pela metade quando o payload é inválido', function () {
    $payload = pizzaImportPayload();
    // Último produto inválido: o que já foi validado antes dele não pode
    // chegar ao banco.
    $payload['products'][2]['price_cents'] = -1;

    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', $payload)
        ->assertStatus(422);

    expect(Category::count())->toBe(0)
        ->and(Product::count())->toBe(0)
        ->and(ModifierGroup::count())->toBe(0);
});

it('reimporta o mesmo modelo sem colidir slug', function () {
    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    $this->actingAs($this->user)
        ->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertCreated();

    expect(Product::where('name', 'Pizza Grande')->count())->toBe(2)
        ->and(Product::pluck('slug')->unique()->count())->toBe(Product::count());
});

it('exige autenticação', function () {
    $this->postJson('/api/admin/menu/import', pizzaImportPayload())
        ->assertUnauthorized();
});
