<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * A verificação de robô nas duas rotas públicas de credencial: login e cadastro.
 *
 * O resto da suíte roda com a verificação desligada (sem secret configurado),
 * que é o comportamento de desenvolvimento. Aqui o secret é definido de
 * propósito para exercitar o caminho ligado, com o siteverify sob Http::fake.
 */

const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

function enableTurnstile(): void
{
    config(['services.turnstile.secret' => 'secret-de-teste']);
}

/** Resposta de sucesso do siteverify. */
function turnstileAccepts(): void
{
    Http::fake([SITEVERIFY => Http::response(['success' => true])]);
}

/** Recusa com o código que a Cloudflare devolve para token já usado. */
function turnstileRejects(): void
{
    Http::fake([SITEVERIFY => Http::response([
        'success' => false,
        'error-codes' => ['timeout-or-duplicate'],
    ])]);
}

function loginPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'dono@loja-a.test',
        'password' => 'senha-correta',
        'tenant' => 'loja-a',
        'cf-turnstile-response' => 'token-do-widget',
    ], $overrides);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);

    User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'dono@loja-a.test',
        'password' => bcrypt('senha-correta'),
        'role' => 'owner',
    ]);

    Plan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price_cents' => 9900,
        'max_products' => 500,
        'allows_online_payment' => true,
    ]);
});

it('deixa o login passar sem token quando não há secret configurado', function () {
    // Ambiente local e a própria suíte: sem chaves da Cloudflare, exigir o
    // token só impediria o desenvolvimento.
    Http::fake();

    $this->postJson('/api/auth/login', loginPayload(['cf-turnstile-response' => null]))
        ->assertOk();

    Http::assertNothingSent();
});

it('recusa o login quando o Turnstile não valida o token', function () {
    enableTurnstile();
    turnstileRejects();

    $this->postJson('/api/auth/login', loginPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('cf-turnstile-response');
});

it('recusa o login sem token quando a verificação está ligada', function () {
    enableTurnstile();
    Http::fake();

    $this->postJson('/api/auth/login', loginPayload(['cf-turnstile-response' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('cf-turnstile-response');

    // Token ausente é recusado localmente: não vale gastar uma chamada de rede.
    Http::assertNothingSent();
});

it('permite o login quando o token é válido', function () {
    enableTurnstile();
    turnstileAccepts();

    $this->postJson('/api/auth/login', loginPayload())->assertOk();

    Http::assertSent(fn ($request) => $request->url() === SITEVERIFY
        && $request['secret'] === 'secret-de-teste'
        && $request['response'] === 'token-do-widget');
});

it('verifica o robô antes de checar a senha', function () {
    // Ordem importa: se a senha fosse checada primeiro, a rota continuaria
    // servindo como oráculo de brute force para quem ignora o widget.
    enableTurnstile();
    turnstileRejects();

    $this->postJson('/api/auth/login', loginPayload(['password' => 'senha-errada']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('cf-turnstile-response')
        ->assertJsonMissingValidationErrors('email');
});

it('recusa o cadastro de loja quando o Turnstile não valida', function () {
    enableTurnstile();
    turnstileRejects();

    $this->postJson('/api/register', [
        'store_name' => 'Cantina da Nona',
        'slug' => 'cantina-da-nona',
        'owner_name' => 'Ana Souza',
        'email' => 'ana@cantina.test',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
        'accepted_terms' => true,
        'cf-turnstile-response' => 'token-do-widget',
    ])->assertStatus(422)->assertJsonValidationErrors('cf-turnstile-response');

    expect(Tenant::where('slug', 'cantina-da-nona')->exists())->toBeFalse();
});

it('permite o cadastro quando o token é válido', function () {
    enableTurnstile();
    turnstileAccepts();

    $this->postJson('/api/register', [
        'store_name' => 'Cantina da Nona',
        'slug' => 'cantina-da-nona',
        'owner_name' => 'Ana Souza',
        'email' => 'ana@cantina.test',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
        'accepted_terms' => true,
        'cf-turnstile-response' => 'token-do-widget',
    ])->assertCreated();

    expect(Tenant::where('slug', 'cantina-da-nona')->exists())->toBeTrue();
});

it('deixa passar quando a Cloudflare está fora do ar', function () {
    // Falha aberta de propósito: uma indisponibilidade da Cloudflare não pode
    // derrubar o login de toda a plataforma. O rate limit continua valendo.
    enableTurnstile();
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->postJson('/api/auth/login', loginPayload())->assertOk();
});
