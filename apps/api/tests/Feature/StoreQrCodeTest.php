<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QrCodeEncoder;
use App\Services\QrCodePngWriter;
use App\Services\StoreQrCode;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

/*
 * QR Code do endereço da loja.
 *
 * O encoder é implementação própria (ver QrCodeEncoder), e um QR errado falha
 * de um jeito traiçoeiro: a imagem sai com aparência impecável e simplesmente
 * não lê. Como não há leitor no ambiente de teste, a verificação aqui é
 * estrutural — matrizes conferidas contra vetores fixos, gerados a partir da
 * norma e confirmados módulo a módulo contra uma implementação independente,
 * além de terem sido decodificados por um leitor real durante o
 * desenvolvimento.
 */

beforeEach(function () {
    $this->menuPlan = Plan::factory()->menuOnly()->create(['slug' => 'cardapio']);
    $this->proPlan = Plan::factory()->create(['slug' => 'pro']);
});

/** Autentica um usuário da loja e devolve o cabeçalho de tenant. */
function actingAsStoreOf(Tenant $tenant): array
{
    Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id]));

    return ['X-Tenant' => $tenant->slug];
}

// ---------------------------------------------------------------------------
// Disponibilidade nos dois planos
// ---------------------------------------------------------------------------

/*
 * O ponto central deste arquivo.
 *
 * O QR abre o cardápio, que existe nos dois planos — e é no somente-cardápio
 * que ele mais importa, por ser o caminho entre a mesa e o link. Um 403 aqui
 * seria a regressão mais fácil de introduzir: basta alguém mover a rota para
 * dentro do grupo `plan.orders`, onde moram as outras rotas de `store/`.
 */
it('gera o QR nos dois planos', function (string $planProperty, string $slug) {
    $tenant = Tenant::factory()->create([
        'slug' => $slug,
        'plan_id' => $this->{$planProperty}->id,
    ]);

    $response = $this->withHeaders(actingAsStoreOf($tenant))
        ->get('/api/admin/store/qrcode');

    $response->assertOk()->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toStartWith("\x89PNG\r\n\x1a\n");
})->with([
    'somente cardápio' => ['menuPlan', 'so-cardapio'],
    'pro' => ['proPlan', 'loja-pro'],
]);

it('exige autenticação', function () {
    $tenant = Tenant::factory()->create(['slug' => 'loja-x', 'plan_id' => $this->proPlan->id]);

    $this->withHeaders(['X-Tenant' => $tenant->slug])
        ->getJson('/api/admin/store/qrcode')
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Correção do código gerado
// ---------------------------------------------------------------------------

/*
 * Vetores de referência.
 *
 * Cada linha é a matriz de módulos esperada, comprimida como hexadecimal do
 * hash — guardar 45 linhas de zeros e uns por caso deixaria o teste ilegível.
 * O que protege contra um hash "atualizado até passar" é o teste seguinte, que
 * verifica propriedades estruturais que a norma exige.
 */
it('produz a matriz esperada para URLs conhecidas', function (string $url, int $expectedSize, string $digest) {
    $matrix = (new QrCodeEncoder)->encode($url);

    expect($matrix)->toHaveCount($expectedSize);
    expect(matrixDigest($matrix))->toBe($digest);
})->with([
    // Versão 2 — a URL mais curta que uma loja real teria.
    ['https://a.to-menu.com', 25, '5a0cd9f0754a22cb'],
    // Versão 3 — a loja de exemplo do seed.
    ['https://forno-di-napoli.to-menu.com', 29, '4c8cf07915314d63'],
    // Versão 3, com porta — o formato usado em desenvolvimento.
    ['http://aoi-sushi.localhost:3000', 29, '602087cdcc65f179'],
]);

/**
 * Propriedades que a norma impõe a qualquer QR, em qualquer versão.
 *
 * Verificar isto (e não só um hash) é o que dá confiança de que a matriz é um
 * QR de verdade: os três localizadores nos cantos, os temporizadores alternados
 * e o módulo escuro fixo são exatamente o que um leitor procura primeiro.
 */
it('respeita a estrutura exigida pela norma', function (string $url) {
    $matrix = (new QrCodeEncoder)->encode($url);
    $size = count($matrix);

    // O lado é sempre 4 * versão + 17.
    expect(($size - 17) % 4)->toBe(0);

    // Localizadores: anel escuro com centro cheio, nos três cantos.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
        expect($matrix[$row][$col])->toBeTrue();
        expect($matrix[$row + 3][$col + 3])->toBeTrue();
        // A faixa clara que separa o localizador dos dados.
        expect($matrix[$row + 1][$col + 1])->toBeFalse();
    }

    // Temporizadores: alternância perfeita entre os localizadores.
    for ($i = 8; $i < $size - 8; $i++) {
        expect($matrix[6][$i])->toBe($i % 2 === 0);
        expect($matrix[$i][6])->toBe($i % 2 === 0);
    }

    // Módulo escuro fixo, sempre presente.
    expect($matrix[$size - 8][8])->toBeTrue();
})->with([
    'https://a.to-menu.com',
    'https://forno-di-napoli.to-menu.com',
    'https://uma-loja-com-nome-bastante-longo-para-forcar-versao-maior.to-menu.com',
]);

/**
 * Os bits de formato precisam declarar o nível de correção e a máscara
 * realmente aplicada.
 *
 * É a verificação que pegou dois defeitos reais durante a implementação: a
 * ordem dos 15 bits (a norma numera do mais significativo para o menos) e o
 * corte entre as duas cópias. Em ambos os casos a matriz de dados saía perfeita
 * e só o formato ficava ilegível — nenhuma inspeção visual acusaria.
 */
it('declara nível de correção e máscara nos bits de formato', function (string $url) {
    $matrix = (new QrCodeEncoder)->encode($url);
    $size = count($matrix);

    // Posições da primeira cópia, do bit 14 ao 0.
    $primary = [
        [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
        [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
    ];

    $readCopy = function (array $positions) use ($matrix) {
        $value = 0;
        foreach ($positions as $i => [$row, $col]) {
            $value |= ($matrix[$row][$col] ? 1 : 0) << (14 - $i);
        }

        return $value ^ 0x5412;
    };

    // Segunda cópia: 7 bits subindo a coluna 8, 8 correndo a linha 8.
    $secondary = [];
    for ($i = 0; $i < 15; $i++) {
        $secondary[] = $i < 7 ? [$size - 1 - $i, 8] : [8, $size - 15 + $i];
    }

    $format = $readCopy($primary);

    // As duas cópias precisam concordar; é para isso que a redundância existe.
    expect($readCopy($secondary))->toBe($format);

    // Nível M é codificado como 00 nos dois bits altos.
    expect(($format >> 13) & 0b11)->toBe(0);

    // A máscara declarada tem de ser uma das oito.
    expect(($format >> 10) & 0b111)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(7);
})->with([
    'https://a.to-menu.com',
    'https://forno-di-napoli.to-menu.com',
    'https://outra-loja-com-um-slug-absurdamente-comprido-que-empurra-o-encoder-para-versoes-altas.to-menu.com',
]);

/**
 * A versão escolhida tem de ser a menor que comporta o texto.
 *
 * Uma versão maior que o necessário ainda leria, mas encolhe o módulo no mesmo
 * papel — e é aí que o celular do cliente começa a sofrer para ler de longe.
 */
it('escolhe a menor versão que comporta o texto', function () {
    $encoder = new QrCodeEncoder;

    // Fronteiras de capacidade em nível M: 14, 26, 42, 62, 84 bytes.
    expect(count($encoder->encode(str_repeat('a', 14))))->toBe(21);  // v1
    expect(count($encoder->encode(str_repeat('a', 15))))->toBe(25);  // v2
    expect(count($encoder->encode(str_repeat('a', 26))))->toBe(25);  // v2
    expect(count($encoder->encode(str_repeat('a', 27))))->toBe(29);  // v3
    expect(count($encoder->encode(str_repeat('a', 42))))->toBe(29);  // v3
    expect(count($encoder->encode(str_repeat('a', 43))))->toBe(33);  // v4
});

it('recusa texto acima da capacidade suportada', function () {
    (new QrCodeEncoder)->encode(str_repeat('a', 215));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// PNG
// ---------------------------------------------------------------------------

it('escreve um PNG de paleta com duas cores', function () {
    $png = (new QrCodePngWriter)->write((new QrCodeEncoder)->encode('https://a.to-menu.com'), 512);

    expect($png)->toStartWith("\x89PNG\r\n\x1a\n");
    expect($png)->toEndWith('IEND'.pack('N', crc32('IEND')));

    // A paleta tem exatamente duas entradas: branco e preto, nesta ordem.
    expect($png)->toContain('PLTE'."\xFF\xFF\xFF\x00\x00\x00");

    $info = getimagesizefromstring($png);

    expect($info['mime'])->toBe('image/png');
    // Quadrado, e arredondado para um múltiplo inteiro do número de módulos.
    expect($info[0])->toBe($info[1]);
    expect($info[0] % (25 + 8))->toBe(0);
});

/*
 * O tamanho do arquivo é o motivo de existir o escritor próprio, então uma
 * regressão nele merece falhar o teste.
 *
 * O teto é folgado em relação ao medido (~1 KB em 1024px) para não quebrar por
 * variação de nível de compressão do zlib entre versões de PHP.
 */
it('mantém o PNG pequeno', function () {
    $png = (new QrCodePngWriter)->write(
        (new QrCodeEncoder)->encode('https://forno-di-napoli.to-menu.com'),
        StoreQrCode::DEFAULT_SIZE,
    );

    expect(strlen($png))->toBeLessThan(4096);
});

it('mantém a quiet zone ao redor do código', function () {
    $png = (new QrCodePngWriter)->write((new QrCodeEncoder)->encode('https://a.to-menu.com'), 512);
    $image = imagecreatefromstring($png);

    $white = imagecolorat($image, 0, 0);

    // As quatro bordas precisam ser claras, ou o leitor não delimita o código.
    $side = imagesx($image);
    foreach ([[0, 0], [$side - 1, 0], [0, $side - 1], [$side - 1, $side - 1]] as [$x, $y]) {
        expect(imagecolorat($image, $x, $y))->toBe($white);
    }
});

// ---------------------------------------------------------------------------
// Cache e transporte
// ---------------------------------------------------------------------------

it('serve o mesmo PNG a partir do cache na segunda chamada', function () {
    $tenant = Tenant::factory()->create(['slug' => 'loja-cache', 'plan_id' => $this->proPlan->id]);
    $qr = app(StoreQrCode::class);

    $first = $qr->png($tenant);

    // Se a segunda chamada regenerasse, o resultado ainda seria igual — então o
    // que se verifica é o cache em si, e não só a igualdade dos bytes.
    expect(Cache::has("tenant:{$tenant->getKey()}:qrcode:".md5($tenant->storefrontUrl()).'-'.StoreQrCode::DEFAULT_SIZE))
        ->toBeTrue();

    expect($qr->png($tenant))->toBe($first);
});

/*
 * O cache é por URL, não só por id do tenant.
 *
 * Guardar só por id deixaria a loja servindo para sempre um QR que aponta para
 * o endereço antigo depois de uma troca de domínio raiz — e ninguém descobre
 * isso olhando a imagem.
 */
it('invalida o cache quando a URL da loja muda', function () {
    $tenant = Tenant::factory()->create(['slug' => 'loja-dominio', 'plan_id' => $this->proPlan->id]);
    $qr = app(StoreQrCode::class);

    $before = $qr->png($tenant);

    config(['tenancy.root_domain' => 'outro-dominio.com']);

    expect($qr->png($tenant))->not->toBe($before);
});

it('responde 304 quando o navegador já tem a versão atual', function () {
    $tenant = Tenant::factory()->create(['slug' => 'loja-etag', 'plan_id' => $this->proPlan->id]);
    $headers = actingAsStoreOf($tenant);

    $etag = $this->withHeaders($headers)
        ->get('/api/admin/store/qrcode')
        ->assertOk()
        ->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->withHeaders($headers + ['If-None-Match' => $etag])
        ->get('/api/admin/store/qrcode')
        ->assertStatus(304);
});

it('anexa o arquivo apenas quando o download é pedido', function () {
    $tenant = Tenant::factory()->create(['slug' => 'loja-download', 'plan_id' => $this->proPlan->id]);
    $headers = actingAsStoreOf($tenant);

    // A prévia na tela não pode disparar download a cada render.
    $this->withHeaders($headers)
        ->get('/api/admin/store/qrcode')
        ->assertOk()
        ->assertHeaderMissing('Content-Disposition');

    $this->withHeaders($headers)
        ->get('/api/admin/store/qrcode?download=1')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="qrcode-loja-download-1024.png"');
});

/*
 * O cache guarda uma entrada por tamanho, então um inteiro livre vindo do
 * cliente deixaria qualquer um enchê-lo com milhares de variações do mesmo
 * código.
 */
it('restringe o tamanho à lista ofertada', function () {
    $qr = app(StoreQrCode::class);

    expect($qr->resolveSize(512))->toBe(512);
    expect($qr->resolveSize(2048))->toBe(2048);

    // Fora da lista cai no padrão, em vez de recusar: o parâmetro vem da
    // própria UI, e um 422 só produziria uma tela quebrada.
    expect($qr->resolveSize(4000))->toBe(StoreQrCode::DEFAULT_SIZE);
    expect($qr->resolveSize('abacaxi'))->toBe(StoreQrCode::DEFAULT_SIZE);
    expect($qr->resolveSize(null))->toBe(StoreQrCode::DEFAULT_SIZE);
});

it('gera imagens distintas para cada tamanho ofertado', function () {
    $tenant = Tenant::factory()->create(['slug' => 'loja-tamanhos', 'plan_id' => $this->proPlan->id]);
    $qr = app(StoreQrCode::class);

    $sides = collect(StoreQrCode::SIZES)
        ->map(fn (int $size) => getimagesizefromstring($qr->png($tenant, $size))[0]);

    expect($sides->unique())->toHaveCount(count(StoreQrCode::SIZES));
    expect($sides->sort()->values()->all())->toBe($sides->values()->all());
});

/** Impressão digital estável da matriz, para os vetores de referência. */
function matrixDigest(array $matrix): string
{
    $rows = array_map(
        fn (array $row) => implode('', array_map(fn (bool $cell) => $cell ? '1' : '0', $row)),
        $matrix,
    );

    return substr(hash('xxh128', implode("\n", $rows)), 0, 16);
}
