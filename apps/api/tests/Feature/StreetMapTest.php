<?php

use App\Jobs\RefreshStreetMap;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use App\Services\StreetMapBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-mapa']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    actingAsTenant($this->tenant);
    TenantSettings::create(['tenant_id' => $this->tenant->id]);
    forgetTenant();

    Sanctum::actingAs($this->user);
});

/** Resposta mínima do Nominatim: um ponto reconhecido. */
function fakeGeocode(): array
{
    return [[
        'lat' => '-23.5613',
        'lon' => '-46.6565',
    ]];
}

/**
 * Resposta do Overpass com `$count` vias retas.
 *
 * A geometria não precisa ser realista — o que se testa é a projeção e o
 * critério de "vale a pena desenhar", não a fidelidade cartográfica.
 */
function fakeOverpass(int $count, string $highway = 'residential'): array
{
    $elements = [];

    for ($i = 0; $i < $count; $i++) {
        $offset = $i * 0.0002;

        $elements[] = [
            'tags' => ['highway' => $highway],
            'geometry' => [
                ['lat' => -23.5613 + $offset, 'lon' => -46.6565],
                ['lat' => -23.5613 + $offset, 'lon' => -46.6560],
            ],
        ];
    }

    return ['elements' => $elements];
}

// ---------------------------------------------------------------------------
// Construção do traçado
// ---------------------------------------------------------------------------

it('desenha o traçado a partir do endereço', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response(fakeOverpass(10)),
    ]);

    $paths = app(StreetMapBuilder::class)->build('Rua Augusta, 1200 — São Paulo');

    expect($paths)->toHaveCount(10)
        // Coordenadas de viewBox, não lat/lng: a projeção já aconteceu.
        ->and($paths[0]['d'])->toStartWith('M')
        ->and($paths[0]['width'])->toBeFloat();
});

it('marca as vias principais com traço mais grosso que as locais', function () {
    // As duas classes na mesma resposta: um segundo `Http::fake` não substitui
    // o primeiro, e as duas chamadas acabariam lendo a mesma via.
    $elements = array_merge(
        fakeOverpass(4, 'primary')['elements'],
        fakeOverpass(4, 'residential')['elements'],
    );

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response(['elements' => $elements]),
    ]);

    $widths = collect(app(StreetMapBuilder::class)->build('Avenida Paulista'))
        ->pluck('width');

    // Sem a hierarquia o desenho vira um emaranhado uniforme e o visitante
    // perde a referência da avenida que usa para se localizar.
    expect($widths->max())->toBeGreaterThan($widths->min());
});

it('centra o desenho no endereço geocodificado', function () {
    // Oito cópias da mesma via, e não uma: abaixo do piso de MIN_WAYS o build
    // devolveria null e não haveria projeção para inspecionar.
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response(['elements' => array_fill(0, 8, [
            'tags' => ['highway' => 'residential'],
            'geometry' => [
                ['lat' => -23.5613, 'lon' => -46.6565],
                ['lat' => -23.5613, 'lon' => -46.6560],
            ],
        ])]),
    ]);

    $paths = app(StreetMapBuilder::class)->build('Rua Augusta');
    $center = StreetMapBuilder::MAP_SIZE / 2;

    // O primeiro ponto é o centro do mapa: deve cair no meio do viewBox.
    expect($paths[0]['d'])->toStartWith('M'.number_format($center, 1, '.', '').' ');
});

it('não desenha quando há vias de menos para parecer um mapa', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response(fakeOverpass(3)),
    ]);

    // Meia dúzia de riscos soltos lê como defeito, não como mapa.
    expect(app(StreetMapBuilder::class)->build('Rua Deserta'))->toBeNull();
});

it('devolve nulo sem estourar quando os serviços falham', function (int $status) {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response('erro', $status),
    ]);

    expect(app(StreetMapBuilder::class)->build('Rua Augusta'))->toBeNull();
})->with([
    'rate limit' => [429],
    'gateway timeout' => [504],
    'erro interno' => [500],
]);

it('devolve nulo quando o endereço não é reconhecido', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([]),
    ]);

    expect(app(StreetMapBuilder::class)->build('Endereço Inexistente'))->toBeNull();
});

it('nem tenta geocodificar endereço vazio', function (?string $address) {
    Http::fake();

    expect(app(StreetMapBuilder::class)->build($address))->toBeNull();

    Http::assertNothingSent();
})->with(['nulo' => [null], 'vazio' => [''], 'só espaços' => ['   ']]);

// ---------------------------------------------------------------------------
// Job
// ---------------------------------------------------------------------------

it('grava o traçado e o endereço que o gerou', function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response(fakeOverpass(10)),
    ]);

    $settings = TenantSettings::withoutGlobalScopes()->find($this->tenant->id);
    $settings->update(['address' => 'Rua Augusta, 1200']);

    (new RefreshStreetMap($this->tenant->id))->handle(app(StreetMapBuilder::class));

    $settings->refresh();

    expect($settings->street_map)->toHaveCount(10)
        ->and($settings->street_map_address)->toBe('Rua Augusta, 1200');
});

it('preserva o mapa anterior quando o serviço falha no mesmo endereço', function () {
    $settings = TenantSettings::withoutGlobalScopes()->find($this->tenant->id);
    $settings->update([
        'address' => 'Rua Augusta, 1200',
        'street_map' => [['d' => 'M0 0L10 10', 'width' => 1.1]],
        'street_map_address' => 'Rua Augusta, 1200',
    ]);

    Http::fake(['overpass-api.de/*' => Http::response('erro', 504)]);

    (new RefreshStreetMap($this->tenant->id))->handle(app(StreetMapBuilder::class));

    // O mapa antigo é do mesmo endereço, então continua correto: um serviço
    // fora do ar não deve fazer a loja perder o mapa que já tinha.
    expect($settings->refresh()->street_map)->toHaveCount(1);
});

it('descarta o mapa antigo quando o endereço mudou e o novo não veio', function () {
    $settings = TenantSettings::withoutGlobalScopes()->find($this->tenant->id);
    $settings->update([
        'address' => 'Rua Nova, 500',
        'street_map' => [['d' => 'M0 0L10 10', 'width' => 1.1]],
        'street_map_address' => 'Rua Antiga, 100',
    ]);

    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response(fakeGeocode()),
        'overpass-api.de/*' => Http::response('erro', 504),
    ]);

    (new RefreshStreetMap($this->tenant->id))->handle(app(StreetMapBuilder::class));

    $settings->refresh();

    // Manter o desenho do endereço antigo apontaria o cliente para o lugar
    // errado — pior do que não ter mapa.
    expect($settings->street_map)->toBeNull()
        ->and($settings->street_map_address)->toBe('Rua Nova, 500');
});

// ---------------------------------------------------------------------------
// Disparo e exposição
// ---------------------------------------------------------------------------

it('enfileira o redesenho quando o lojista salva um endereço novo', function () {
    Queue::fake();

    $this->withHeaders(['X-Tenant' => 'loja-mapa'])
        ->putJson('/api/admin/settings/profile', [
            'name' => 'Loja Mapa',
            'address' => 'Alameda Santos, 800',
        ])->assertOk();

    Queue::assertPushed(RefreshStreetMap::class);
});

it('não enfileira nada quando o endereço não mudou', function () {
    $settings = TenantSettings::withoutGlobalScopes()->find($this->tenant->id);
    $settings->update([
        'address' => 'Alameda Santos, 800',
        'street_map_address' => 'Alameda Santos, 800',
    ]);

    Queue::fake();

    $this->withHeaders(['X-Tenant' => 'loja-mapa'])
        ->putJson('/api/admin/settings/profile', [
            'name' => 'Loja Mapa',
            'address' => 'Alameda Santos, 800',
        ])->assertOk();

    // Sem isto, cada "Salvar" do perfil consumiria a cota de um serviço
    // público para redesenhar exatamente o mesmo mapa.
    Queue::assertNotPushed(RefreshStreetMap::class);
});

it('envia o traçado no cardápio quando ele corresponde ao endereço atual', function () {
    TenantSettings::withoutGlobalScopes()->find($this->tenant->id)->update([
        'address' => 'Rua Augusta, 1200',
        'street_map' => [['d' => 'M0 0L10 10', 'width' => 1.1]],
        'street_map_address' => 'Rua Augusta, 1200',
    ]);

    $this->getJson('/api/loja-mapa/menu')
        ->assertOk()
        ->assertJsonCount(1, 'tenant.streetMap');
});

it('omite o traçado enquanto ele ainda é do endereço anterior', function () {
    TenantSettings::withoutGlobalScopes()->find($this->tenant->id)->update([
        'address' => 'Rua Nova, 500',
        'street_map' => [['d' => 'M0 0L10 10', 'width' => 1.1]],
        'street_map_address' => 'Rua Antiga, 100',
    ]);

    // Janela entre salvar o endereço e o job concluir: mostrar o mapa antigo ao
    // lado do endereço novo apontaria o cliente para o lugar errado.
    $this->getJson('/api/loja-mapa/menu')
        ->assertOk()
        ->assertJsonPath('tenant.streetMap', null);
});
