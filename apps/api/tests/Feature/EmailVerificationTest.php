<?php

use App\Mail\VerifyEmailLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmailVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    // O throttle por conta vive no RateLimiter e sobrevive entre testes,
    // fazendo o segundo reenvio do mesmo e-mail falhar sem relação com o que
    // está sendo verificado.
    RateLimiter::clear('verify-email:dono@loja-a.com:loja-a');

    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->unverified()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'dono@loja-a.com',
        'password' => Hash::make('senha-forte-123'),
        'role' => 'owner',
    ]);
});

/** Emite um link de verdade e devolve o token em texto puro do e-mail. */
function issueToken(User $user, Tenant $tenant): string
{
    Mail::fake();

    app(EmailVerifier::class)->sendLink($user, $tenant);

    $mail = null;
    Mail::assertSent(VerifyEmailLink::class, function ($m) use (&$mail) {
        $mail = $m;

        return true;
    });

    return $mail->token;
}

it('confirma a conta e devolve uma sessão ao consumir o token', function () {
    $token = issueToken($this->user, $this->tenant);

    $response = $this->postJson('/api/auth/verify-email', [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertOk();

    expect($response->json('token'))->not->toBeEmpty()
        ->and($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('guarda o token com hash, e não em texto puro', function () {
    // Um dump do banco não pode permitir confirmar contas alheias.
    $token = issueToken($this->user, $this->tenant);

    $stored = DB::table('email_verification_tokens')
        ->where('user_id', $this->user->id)
        ->value('token');

    expect($stored)->not->toBe($token)
        ->and(Hash::check($token, $stored))->toBeTrue();
});

it('recusa o token depois da validade', function () {
    $token = issueToken($this->user, $this->tenant);

    // Um minuto além do TTL: a fronteira exata é o que o bug de sinal do
    // Carbon 3 deixava passar no fluxo de senha.
    $this->travel(EmailVerifier::TOKEN_TTL_MINUTES + 1)->minutes();

    $this->postJson('/api/auth/verify-email', [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertStatus(422)->assertJsonValidationErrors('token');

    expect($this->user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('aceita o token dentro da validade', function () {
    $token = issueToken($this->user, $this->tenant);

    $this->travel(EmailVerifier::TOKEN_TTL_MINUTES - 1)->minutes();

    $this->postJson('/api/auth/verify-email', [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertOk();
});

it('não aceita o mesmo token duas vezes', function () {
    $token = issueToken($this->user, $this->tenant);

    $payload = [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ];

    $this->postJson('/api/auth/verify-email', $payload)->assertOk();

    // Segunda vez: responde sucesso (o caso comum é reabrir o mesmo link), mas
    // sem emitir sessão — um e-mail encaminhado não pode virar acesso.
    $response = $this->postJson('/api/auth/verify-email', $payload)->assertOk();

    expect($response->json('alreadyVerified'))->toBeTrue()
        ->and($response->json('token'))->toBeNull();
});

/*
 * O token é emitido para o par (loja, e-mail).
 *
 * `users` é único por (tenant_id, email), então o mesmo endereço pode ser dono
 * de uma pizzaria e gerente de um sushi — confirmar uma conta não pode
 * confirmar a outra.
 */
it('não aceita o token de uma loja para confirmar a conta de outra', function () {
    $outra = Tenant::factory()->create(['slug' => 'loja-b']);
    $mesmoEmail = User::factory()->unverified()->create([
        'tenant_id' => $outra->id,
        'email' => 'dono@loja-a.com',
    ]);

    $token = issueToken($this->user, $this->tenant);

    $this->postJson('/api/auth/verify-email', [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-b',
    ])->assertStatus(422)->assertJsonValidationErrors('token');

    expect($mesmoEmail->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('recusa um token inventado', function () {
    issueToken($this->user, $this->tenant);

    $this->postJson('/api/auth/verify-email', [
        'token' => str_repeat('a', 64),
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertStatus(422)->assertJsonValidationErrors('token');

    expect($this->user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('reenvia o link e invalida o token anterior', function () {
    $antigo = issueToken($this->user, $this->tenant);

    Mail::fake();
    $this->postJson('/api/auth/verify-email/resend', [
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertOk();

    Mail::assertSent(VerifyEmailLink::class, fn ($m) => $m->hasTo('dono@loja-a.com'));

    // Um link antigo encaminhado por engano não pode continuar valendo.
    $this->postJson('/api/auth/verify-email', [
        'token' => $antigo,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertStatus(422);
});

/*
 * A resposta do reenvio não revela se a conta existe.
 *
 * Confirmar que um e-mail está cadastrado numa loja específica permitiria
 * enumerar quem trabalha onde.
 */
it('responde igual para conta inexistente no reenvio', function () {
    Mail::fake();

    $this->postJson('/api/auth/verify-email/resend', [
        'email' => 'ninguem@lugar-nenhum.com',
        'tenant' => 'loja-a',
    ])->assertOk();

    Mail::assertNothingSent();
});

it('não reenvia link para conta já confirmada', function () {
    $this->user->forceFill(['email_verified_at' => now()])->save();

    Mail::fake();

    $this->postJson('/api/auth/verify-email/resend', [
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertOk();

    Mail::assertNothingSent();
});

it('limita o número de reenvios por conta', function () {
    Mail::fake();

    $payload = ['email' => 'dono@loja-a.com', 'tenant' => 'loja-a'];

    // Sem o limite por conta o endpoint vira uma metralhadora de e-mail
    // apontada para a caixa de um lojista.
    $this->postJson('/api/auth/verify-email/resend', $payload)->assertOk();
    $this->postJson('/api/auth/verify-email/resend', $payload)->assertOk();
    $this->postJson('/api/auth/verify-email/resend', $payload)->assertOk();

    $this->postJson('/api/auth/verify-email/resend', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

/*
 * O login é o que torna a confirmação obrigatória.
 */
it('recusa o login enquanto o e-mail não é confirmado', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'dono@loja-a.com',
        'password' => 'senha-forte-123',
        'tenant' => 'loja-a',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_unverified');
});

it('deixa entrar depois de confirmado', function () {
    $token = issueToken($this->user, $this->tenant);

    $this->postJson('/api/auth/verify-email', [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertOk();

    $this->postJson('/api/auth/login', [
        'email' => 'dono@loja-a.com',
        'password' => 'senha-forte-123',
        'tenant' => 'loja-a',
    ])->assertOk();
});

/*
 * A senha é checada antes da confirmação.
 *
 * Responder "confirme seu e-mail" a quem errou a senha revelaria que a conta
 * existe, desfazendo o cuidado da mensagem única de credencial inválida.
 */
it('não revela conta não confirmada para quem erra a senha', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'dono@loja-a.com',
        'password' => 'senha-errada',
        'tenant' => 'loja-a',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

/*
 * Marca do e-mail.
 *
 * O layout do framework troca o nome do remetente pelo LOGO DO LARAVEL quando
 * `config('app.name')` é exatamente "Laravel" — e como esse é o valor padrão,
 * bastava o APP_NAME ficar em branco no servidor para o lojista receber a
 * marca de outra empresa na confirmação de cadastro. Foi o que aconteceu em
 * produção.
 *
 * O teste força o pior caso (app.name de volta ao padrão) porque a versão
 * boa passa mesmo com o bug presente: só o valor "Laravel" dispara a regra.
 */
it('nunca envia o logo do Laravel, mesmo com o app.name no padrão', function () {
    config(['app.name' => 'Laravel', 'mail.logo_url' => null]);

    $html = (new VerifyEmailLink($this->user, $this->tenant, 'tok', 30))->render();

    expect($html)->not->toContain('laravel.com/img');
});

it('usa o wordmark do ToMenu no cabeçalho quando há logo configurado', function () {
    config(['mail.logo_url' => 'https://app.to-menu.com/tomenu-wordmark.png']);

    $html = (new VerifyEmailLink($this->user, $this->tenant, 'tok', 30))->render();

    expect($html)->toContain('https://app.to-menu.com/tomenu-wordmark.png');
});

/*
 * Sem logo configurado o cabeçalho cai no nome em texto — o caso de
 * desenvolvimento, onde o host público não existe. O que não pode acontecer é
 * o cabeçalho ficar vazio.
 */
it('cai no nome da marca em texto quando não há logo configurado', function () {
    config(['mail.logo_url' => null]);

    $html = (new VerifyEmailLink($this->user, $this->tenant, 'tok', 30))->render();

    expect($html)->toContain('ToMenu')->not->toContain('<img');
});

/*
 * O nome do remetente é o primeiro campo lido na caixa de entrada. O default
 * do framework encadeava MAIL_FROM_NAME → APP_NAME → "Laravel", com duas
 * chances de cair na marca errada em silêncio.
 */
it('não deixa o remetente cair em "Laravel" sem configuração', function () {
    expect(config('mail.from.name'))->not->toBe('Laravel');
});
