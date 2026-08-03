<?php

use App\Mail\VerifyEmailLink;
use App\Mail\WelcomeStoreOwner;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

/** Token em texto puro do link de confirmação que acabou de ser enviado. */
function capturedVerificationToken(): string
{
    $mail = null;
    Mail::assertSent(VerifyEmailLink::class, function ($m) use (&$mail) {
        $mail = $m;

        return true;
    });

    return $mail->token;
}

/**
 * Cadastra e confirma o e-mail, devolvendo o token de sessão.
 *
 * O cadastro sozinho já não entrega credencial: quem precisa de uma sessão
 * para exercitar o painel tem de passar pela confirmação, como o lojista.
 */
function registerAndVerify(array $overrides = []): string
{
    Mail::fake();

    $payload = signupPayload($overrides);

    test()->postJson('/api/register', $payload)->assertCreated();

    return test()->postJson('/api/auth/verify-email', [
        'token' => capturedVerificationToken(),
        'email' => $payload['email'],
        'tenant' => $payload['slug'],
    ])->assertOk()->json('token');
}

it('cria loja, dono e configurações padrão em um único cadastro', function () {
    $response = $this->postJson('/api/register', signupPayload());

    $response->assertCreated()
        ->assertJsonPath('tenant.slug', 'cantina-da-nona')
        ->assertJsonPath('tenant.onboardingStep', 1);

    // Nenhuma credencial no cadastro: a loja nasce com o e-mail por confirmar,
    // e a sessão só é emitida quando o link do e-mail é consumido.
    expect($response->json('token'))->toBeNull()
        ->and($response->json('pendingVerification'))->toBeTrue();

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

it('o token devolvido na confirmação já acessa o admin da nova loja', function () {
    $token = registerAndVerify();

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant' => 'cantina-da-nona',
    ])->getJson('/api/admin/settings')->assertOk();
});

it('o token de uma loja não acessa o admin de outra', function () {
    $token = registerAndVerify();

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

/*
 * As boas-vindas mudaram de gatilho: saem na confirmação, não no cadastro.
 *
 * "Sua loja está no ar" chegando antes da confirmação levaria o lojista a um
 * painel que o login ainda recusa.
 */
it('não envia boas-vindas antes da confirmação do e-mail', function () {
    Mail::fake();

    $this->postJson('/api/register', signupPayload())->assertCreated();

    Mail::assertNotQueued(WelcomeStoreOwner::class);
});

it('envia as boas-vindas quando o e-mail é confirmado', function () {
    Mail::fake();

    $this->postJson('/api/register', signupPayload())->assertCreated();

    $this->postJson('/api/auth/verify-email', [
        'token' => capturedVerificationToken(),
        'email' => 'ana@cantina.test',
        'tenant' => 'cantina-da-nona',
    ])->assertOk();

    Mail::assertQueued(WelcomeStoreOwner::class, function ($mail) {
        return $mail->hasTo('ana@cantina.test')
            && $mail->tenant->slug === 'cantina-da-nona'
            && $mail->user->name === 'Ana Souza';
    });
});

/*
 * O e-mail sai depois do commit, e não de dentro da transação.
 *
 * Enviado lá dentro, um rollback entregaria ao lojista o link de confirmação
 * de uma conta que não existe.
 */
it('não envia e-mail quando o cadastro é recusado', function () {
    Mail::fake();

    $this->postJson('/api/register', signupPayload(['accepted_terms' => false]))
        ->assertStatus(422);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

/*
 * O cadastro já está gravado quando o e-mail sai. Devolver 500 por causa dele
 * mandaria o lojista de volta a um formulário que agora recusaria o slug
 * ocupado por ele mesmo — e a saída, sem sessão, é o reenvio na tela de
 * confirmação.
 */
it('conclui o cadastro mesmo se o envio do e-mail falhar', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Resend fora do ar'));

    $this->postJson('/api/register', signupPayload())
        ->assertCreated()
        ->assertJsonPath('tenant.slug', 'cantina-da-nona');

    expect(Tenant::where('slug', 'cantina-da-nona')->exists())->toBeTrue();
});
