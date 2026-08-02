<?php

use App\Jobs\PurgeMenuCache;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\MenuCachePurger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
});

it('agenda o purge quando um produto do cardápio muda', function () {
    Queue::fake();

    actingAsTenant($this->tenant);
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);
    flushMenuInvalidations();

    Queue::assertPushed(PurgeMenuCache::class, fn ($job) => $job->tenantId === $this->tenant->id);
});

it('agenda o purge quando a categoria muda', function () {
    Queue::fake();

    actingAsTenant($this->tenant);
    Category::factory()->create();
    flushMenuInvalidations();

    Queue::assertPushed(PurgeMenuCache::class);
});

/*
 * O ganho que motivou o coletor: uma edição em lote toca a linha do tenant uma
 * vez só.
 *
 * Antes disto cada save incrementava `menu_version` sozinho, e a linha de
 * `tenants` — a mesma que o OrderNumberGenerator trava para numerar o pedido —
 * era atualizada uma vez por model, dentro da transação. Salvar um produto com
 * seus grupos e modificadores bloqueava a criação de pedidos da loja pelo tempo
 * do lote inteiro.
 */
it('colapsa uma edição em lote num único bump de versão', function () {
    Queue::fake();

    actingAsTenant($this->tenant);
    $before = $this->tenant->fresh()->menu_version;

    $category = Category::factory()->create();
    Product::factory()->count(10)->create(['category_id' => $category->id]);

    // Nada tocou o banco ainda: as invalidações estão apenas acumuladas.
    expect($this->tenant->fresh()->menu_version)->toBe($before);

    flushMenuInvalidations();

    expect($this->tenant->fresh()->menu_version)->toBe($before + 1);
    Queue::assertPushed(PurgeMenuCache::class, 1);
});

it('avisa o storefront e o Cloudflare com a loja que mudou', function () {
    Http::fake();

    config()->set('tenancy.purge.storefront_url', 'http://storefront:3000');
    config()->set('tenancy.purge.revalidate_secret', 'segredo');
    config()->set('tenancy.purge.cloudflare_zone_id', 'zona123');
    config()->set('tenancy.purge.cloudflare_api_token', 'token123');
    config()->set('tenancy.root_domain', 'to-menu.com');
    config()->set('tenancy.storefront_scheme', 'https');
    config()->set('tenancy.storefront_port', null);

    app(MenuCachePurger::class)->purge($this->tenant);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'storefront:3000/api/revalidate')
        && str_contains($request->url(), 'tenant=loja-a')
        && $request->header('x-revalidate-secret')[0] === 'segredo');

    // Purge por host, não purge_everything: a zona é compartilhada por todas as
    // lojas, e esvaziá-la inteira a cada edição penalizaria as outras.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'zones/zona123/purge_cache')
        && $request['hosts'] === ['loja-a.to-menu.com']);
});

it('não chama nada quando o purge não está configurado', function () {
    Http::fake();

    config()->set('tenancy.purge.storefront_url', null);
    config()->set('tenancy.purge.revalidate_secret', null);
    config()->set('tenancy.purge.cloudflare_zone_id', null);
    config()->set('tenancy.purge.cloudflare_api_token', null);

    app(MenuCachePurger::class)->purge($this->tenant);

    Http::assertNothingSent();
});

it('não propaga falha do storefront para quem salvou o cardápio', function () {
    Http::fake(['*' => Http::response('erro', 500)]);

    config()->set('tenancy.purge.storefront_url', 'http://storefront:3000');
    config()->set('tenancy.purge.revalidate_secret', 'segredo');

    // Um purge perdido é degradação — volta-se ao TTL —, não motivo para
    // quebrar o salvamento que o lojista acabou de fazer.
    app(MenuCachePurger::class)->purge($this->tenant);
})->throwsNoExceptions();

/*
 * Reordenar as seções também invalida o cardápio.
 *
 * O endpoint de reorder usa `Builder::update()`, que desce para o query builder
 * e NÃO dispara evento de model — o observer nunca via essa escrita. O lojista
 * arrastava as seções, salvava, e o cardápio publicado continuava na ordem
 * antiga até o TTL vencer. A invalidação é sinalizada à mão no controller, e
 * este teste existe para que ela não se perca numa refatoração.
 */
it('invalida o cardápio ao reordenar as seções', function () {
    $user = App\Models\User::factory()->create(['tenant_id' => $this->tenant->id]);
    Laravel\Sanctum\Sanctum::actingAs($user);

    actingAsTenant($this->tenant);
    $ids = [
        Category::factory()->create(['position' => 1])->id,
        Category::factory()->create(['position' => 2])->id,
    ];
    flushMenuInvalidations();

    $before = $this->tenant->fresh()->menu_version;
    Queue::fake();

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/categories/reorder', ['ids' => array_reverse($ids)])
        ->assertOk();

    flushMenuInvalidations();

    expect($this->tenant->fresh()->menu_version)->toBe($before + 1);
    Queue::assertPushed(PurgeMenuCache::class, 1);
});
