<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price_cents' => 9900,
        'max_products' => 500,
        'allows_online_payment' => true,
    ]);
});

function signupPayload(array $overrides = []): array
{
    return array_merge([
        'store_name' => 'Cantina da Nona',
        'slug' => 'cantina-da-nona',
        'owner_name' => 'Ana Souza',
        'email' => 'ana@cantina.test',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
        // O aceite é obrigatório; o payload base representa o cadastro que dá
        // certo, e sem ele todos os outros casos falhariam por 422 no aceite em
        // vez de exercitar o que cada um se propõe a testar.
        'accepted_terms' => true,
    ], $overrides);
}

it('cria loja, dono e configurações padrão em um único cadastro', function () {
    $response = $this->postJson('/api/register', signupPayload());

    $response->assertCreated()
        ->assertJsonPath('tenant.slug', 'cantina-da-nona')
        ->assertJsonPath('tenant.onboardingStep', 1);

    expect($response->json('token'))->not->toBeEmpty();

    $tenant = Tenant::where('slug', 'cantina-da-nona')->firstOrFail();

    expect($tenant->status)->toBe('trial')
        ->and($tenant->trial_ends_at)->not->toBeNull()
        // Sem settings, o storefront renderizaria sem tema e sem taxas.
        ->and($tenant->settings)->not->toBeNull()
        ->and($tenant->settings->payment_methods)->toBe(['cash']);
});

it('devolve a url de subdomínio da loja recém-criada', function () {
    config(['tenancy.root_domain' => 'tomenu.test', 'tenancy.storefront_port' => 3000]);

    $this->postJson('/api/register', signupPayload())
        ->assertCreated()
        ->assertJsonPath('tenant.storefrontUrl', 'http://cantina-da-nona.tomenu.test:3000');
});

it('recusa slug já usado por outra loja', function () {
    Tenant::factory()->create(['slug' => 'cantina-da-nona']);

    $this->postJson('/api/register', signupPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('recusa slugs reservados pela plataforma', function (string $slug) {
    $this->postJson('/api/register', signupPayload(['slug' => $slug]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with(['www', 'api', 'admin', 'app']);

it('recusa slug com formato inválido', function (string $slug) {
    $this->postJson('/api/register', signupPayload(['slug' => $slug]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with([
    'maiúsculas' => ['Cantina'],
    'espaço' => ['cantina nona'],
    'ponto' => ['cantina.nona'],
    'hífen duplo no fim' => ['cantina-'],
    'muito curto' => ['ab'],
]);

it('exige confirmação de senha', function () {
    $this->postJson('/api/register', signupPayload([
        'password_confirmation' => 'outra-coisa',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('recusa o cadastro sem aceite dos termos', function (mixed $value) {
    $this->postJson('/api/register', signupPayload(['accepted_terms' => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('accepted_terms');

    // A checagem que importa: nada foi criado. Um 422 que ainda assim gravasse
    // o tenant deixaria a loja no ar sem consentimento nenhum.
    expect(Tenant::where('slug', 'cantina-da-nona')->exists())->toBeFalse();
})->with([
    'caixa desmarcada' => [false],
    'string falsa' => ['false'],
    'zero' => [0],
    'campo vazio' => [null],
]);

it('recusa o cadastro quando o campo de aceite nem é enviado', function () {
    $payload = signupPayload();
    unset($payload['accepted_terms']);

    $this->postJson('/api/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('accepted_terms');
});

it('registra data, versão e ip do aceite junto com a loja', function () {
    config(['legal.terms_version' => '2026-08-02']);

    $this->postJson('/api/register', signupPayload())->assertCreated();

    $tenant = Tenant::where('slug', 'cantina-da-nona')->firstOrFail();

    expect($tenant->terms_accepted_at)->not->toBeNull()
        // Sem a versão, o registro não diz *qual* texto foi aceito — que é a
        // única pergunta que ele existe para responder quando os termos mudarem.
        ->and($tenant->terms_version)->toBe('2026-08-02')
        ->and($tenant->terms_accepted_ip)->not->toBeNull();
});

it('grava a versão vigente dos termos, e não uma fixa', function () {
    config(['legal.terms_version' => '2027-01-15']);

    $this->postJson('/api/register', signupPayload())->assertCreated();

    expect(Tenant::where('slug', 'cantina-da-nona')->first()->terms_version)
        ->toBe('2027-01-15');
});

it('informa se um slug está disponível', function () {
    Tenant::factory()->create(['slug' => 'ocupado']);

    $this->getJson('/api/register/check-slug?slug=ocupado')
        ->assertOk()
        ->assertJsonPath('available', false);

    $this->getJson('/api/register/check-slug?slug=livre-mesmo')
        ->assertOk()
        ->assertJsonPath('available', true);
});

it('permite o mesmo email em lojas diferentes', function () {
    $this->postJson('/api/register', signupPayload())->assertCreated();

    // Um mesmo dono pode ter duas lojas; o unique de email é por tenant.
    $this->postJson('/api/register', signupPayload([
        'store_name' => 'Segunda Loja',
        'slug' => 'segunda-loja',
    ]))->assertCreated();

    expect(User::where('email', 'ana@cantina.test')->count())->toBe(2);
});

it('o token devolvido no cadastro já acessa o admin da nova loja', function () {
    $response = $this->postJson('/api/register', signupPayload());
    $token = $response->json('token');

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant' => 'cantina-da-nona',
    ])->getJson('/api/admin/settings')->assertOk();
});

it('o token de uma loja não acessa o admin de outra', function () {
    $token = $this->postJson('/api/register', signupPayload())->json('token');

    $this->postJson('/api/register', signupPayload([
        'store_name' => 'Outra',
        'slug' => 'outra-loja',
        'email' => 'outro@dono.test',
    ]))->assertCreated();

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant' => 'outra-loja',
    ])->getJson('/api/admin/settings')->assertForbidden();
});
