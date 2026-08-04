<?php

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    config()->set('tenancy.cache.cdn_ttl', 30);

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    $response->assertOk();

    // max-age=0 é intencional: o cache do browser é a única camada que o purge
    // não alcança, e era ele que fazia o lojista recarregar a própria loja e
    // continuar vendo o cardápio antigo depois de a origem já ter atualizado.
    expect($response->headers->get('Cache-Control'))->toContain('s-maxage=30')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=0')
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

    $group = ModifierGroup::create([
        'name' => 'Tamanho',
        'min_select' => 1,
        'max_select' => 1,
        'is_required' => true,
    ]);
    Modifier::create([
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

/*
 * O relógio é fixado, e não lido do ambiente.
 *
 * `isOpen` compara o horário declarado com `now()`, então um horário fixo no
 * fixture torna o resultado dependente de QUANDO a suíte roda: com
 * segunda 18:00–23:00 e nenhuma viagem no tempo, este teste passava o dia
 * inteiro e falhava sozinho toda segunda à noite — uma falha que não aponta
 * para nenhum defeito e que se conserta esperando o relógio virar.
 *
 * Terça é o dia desligado no fixture, o que exercita o `enabled: false` do
 * `isOpen` sem depender da hora.
 */
it('publica horário de funcionamento e status de abertura no cardápio', function () {
    // A página da loja no storefront monta a lista de horários e o selo
    // aberto/fechado a partir destes dois campos.
    TenantSettings::create([
        'tenant_id' => $this->tenantA->id,
        'business_hours' => [
            'mon' => ['enabled' => true, 'open' => '18:00', 'close' => '23:00'],
            'tue' => ['enabled' => false, 'open' => '18:00', 'close' => '23:00'],
        ],
    ]);

    $this->travelTo('2026-08-04 20:00:00'); // terça, dia desligado

    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.businessHours.mon.open', '18:00')
        ->assertJsonPath('tenant.businessHours.tue.enabled', false)
        ->assertJsonPath('tenant.isOpen', false);
});

/*
 * O outro lado da decisão de `isOpen`.
 *
 * O teste acima só cobria o caso fechado; sem este, uma regressão que fizesse
 * `isOpen` devolver `false` sempre passaria despercebida — e o selo "aberto"
 * some do storefront no horário em que a loja mais vende.
 */
it('marca a loja como aberta dentro do horário declarado', function () {
    TenantSettings::create([
        'tenant_id' => $this->tenantA->id,
        'business_hours' => [
            'mon' => ['enabled' => true, 'open' => '18:00', 'close' => '23:00'],
        ],
    ]);

    $this->travelTo('2026-08-03 20:00:00'); // segunda, dentro da faixa

    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.isOpen', true);
});

/*
 * Mesma segunda habilitada, porém fora da faixa.
 *
 * Separa "o dia está ligado" de "estamos dentro do horário": sem este caso, um
 * `isOpen` que só olhasse o `enabled` passaria nos dois testes acima.
 */
it('marca a loja como fechada fora do horário do dia habilitado', function () {
    TenantSettings::create([
        'tenant_id' => $this->tenantA->id,
        'business_hours' => [
            'mon' => ['enabled' => true, 'open' => '18:00', 'close' => '23:00'],
        ],
    ]);

    $this->travelTo('2026-08-03 09:00:00'); // segunda de manhã, antes de abrir

    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.isOpen', false);
});

it('respeita o fechamento manual mesmo dentro do horário declarado', function () {
    // O "fechar agora" do painel é o botão de pânico quando a cozinha lota:
    // precisa vencer o horário, senão o cliente monta um pedido que não entra.
    $today = strtolower(now()->format('D'));

    TenantSettings::create([
        'tenant_id' => $this->tenantA->id,
        'business_hours' => [
            $today => ['enabled' => true, 'open' => '00:00', 'close' => '23:59'],
        ],
        'is_open_override' => false,
    ]);

    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')
        ->assertOk()
        ->assertJsonPath('tenant.isOpen', false);
});

/*
 * Stampede na primeira carga.
 *
 * O caminho crítico é o lock ocupado SEM cópia stale disponível: loja nova,
 * primeiro acesso, ou stale_ttl vencido. Antes, esse caso caía direto no build
 * e todos os processos consultavam o banco simultaneamente — o stampede que o
 * lock existe para evitar, no momento em que nada está aquecido.
 *
 * O teste simula o concorrente segurando o lock e conferindo que a request
 * ainda responde o cardápio correto. Sem `block()` a resposta também viria,
 * mas às custas de uma reconstrução por processo.
 */
it('espera o vencedor do lock em vez de reconstruir junto', function () {
    $tenant = $this->tenantA;
    $key = "menu:{$tenant->id}:v{$tenant->menu_version}";

    Cache::forget($key);
    Cache::forget("menu:{$tenant->id}:stale");

    // O concorrente segura o lock e, como faria um processo real, deixa o
    // payload pronto no cache antes de soltá-lo.
    $lock = Cache::lock("{$key}:build", 10);
    expect($lock->get())->toBeTrue();
    Cache::put($key, [
        'tenant' => ['slug' => 'loja-a'],
        'version' => (string) $tenant->menu_version,
        'marca' => 'do-vencedor',
    ], 60);
    $lock->release();

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "categories"')) {
            $queries++;
        }
    });

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    // Serviu o que o vencedor gravou, sem tocar no banco.
    $response->assertOk()->assertJsonPath('marca', 'do-vencedor');
    expect($queries)->toBe(0);
});

it('reconstrói sozinho quando o vencedor do lock demora demais', function () {
    $tenant = $this->tenantA;
    $key = "menu:{$tenant->id}:v{$tenant->menu_version}";

    Cache::forget($key);
    Cache::forget("menu:{$tenant->id}:stale");

    // Lock preso e nada gravado: o esperador estoura o block(3) e assume o
    // trabalho. Preferir o custo extra a deixar o visitante sem resposta.
    $lock = Cache::lock("{$key}:build", 30);
    expect($lock->get())->toBeTrue();

    $response = $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu');

    $lock->release();

    $response->assertOk()->assertJsonPath('tenant.slug', 'loja-a');
});

it('reaproveita o payload que o vencedor do lock gravou', function () {
    $tenant = $this->tenantA;
    $key = "menu:{$tenant->id}:v{$tenant->menu_version}";

    Cache::forget($key);
    Cache::forget("menu:{$tenant->id}:stale");

    // O vencedor terminou: o lock está livre e a chave, quente. A request não
    // deve reconstruir nada — nenhuma query de categoria sai daqui.
    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')->assertOk();

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "categories"')) {
            $queries++;
        }
    });

    $this->withHeader('X-Tenant', 'loja-a')->getJson('/api/menu')->assertOk();

    expect($queries)->toBe(0);
});
