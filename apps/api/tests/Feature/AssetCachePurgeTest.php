<?php

use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use App\Services\AssetCachePurger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    actingAsTenant($this->tenant);
    TenantSettings::create(['tenant_id' => $this->tenant->id]);
    forgetTenant();

    Sanctum::actingAs($this->user);

    config([
        'tenancy.purge.cloudflare_zone_id' => 'zona-teste',
        'tenancy.purge.cloudflare_api_token' => 'token-teste',
    ]);
});

it('invalida na borda a URL da imagem substituída', function () {
    Http::fake();

    app(AssetCachePurger::class)->purge(['https://cdn.exemplo.com/logos/1-logo-100.png']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/zones/zona-teste/purge_cache')
            && $request['files'] === ['https://cdn.exemplo.com/logos/1-logo-100.png'];
    });
});

/*
 * O domínio de assets é compartilhado por todas as lojas. Purgar por hostname
 * — ou pior, `purge_everything` — trocaria um objeto obsoleto por um pico de
 * origem em toda a plataforma a cada troca de logo.
 */
it('nunca purga o host inteiro', function () {
    Http::fake();

    app(AssetCachePurger::class)->purge(['https://cdn.exemplo.com/logos/1-logo-100.png']);

    Http::assertSent(fn ($request) => ! isset($request['hosts'])
        && ! isset($request['purge_everything']));
});

it('não chama a borda quando não há credencial', function () {
    config([
        'tenancy.purge.cloudflare_zone_id' => null,
        'tenancy.purge.cloudflare_api_token' => null,
    ]);

    Http::fake();

    app(AssetCachePurger::class)->purge(['https://cdn.exemplo.com/logos/1-logo-100.png']);

    Http::assertNothingSent();
});

/*
 * Sem disco remoto o `put()` devolve caminho do disco público, que não passa
 * por CDN nenhum — mandar isso para a API do Cloudflare só gastaria cota.
 */
it('ignora caminho local e valor vazio', function () {
    Http::fake();

    app(AssetCachePurger::class)->purge([null, '', '/storage/logos/1-logo-100.png']);

    Http::assertNothingSent();
});

/*
 * A falha da borda não pode derrubar o upload que o lojista acabou de fazer: um
 * purge perdido é degradação, e a borda volta a depender do TTL.
 */
it('não quebra o upload quando o Cloudflare está fora do ar', function () {
    Storage::fake('public');
    Http::fake(fn () => throw new ConnectionException('sem rede'));

    $this->withHeaders(['X-Tenant' => 'loja-a'])
        ->postJson('/api/admin/settings/logo', [
            'logo' => UploadedFile::fake()->create('logo.png', 100, 'image/png'),
        ])
        ->assertOk();

    expect(TenantSettings::find($this->tenant->id)->logo_url)->not->toBeNull();
});
