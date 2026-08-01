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

    Queue::assertPushed(PurgeMenuCache::class, fn ($job) => $job->tenantId === $this->tenant->id);
});

it('agenda o purge quando a categoria muda', function () {
    Queue::fake();

    actingAsTenant($this->tenant);
    Category::factory()->create();

    Queue::assertPushed(PurgeMenuCache::class);
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
