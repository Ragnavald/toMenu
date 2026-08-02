<?php

use App\Mail\PasswordResetLink;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // O throttle por conta é keyed no RateLimiter e sobrevive entre testes,
    // fazendo o segundo pedido do mesmo e-mail falhar sem relação com o que
    // está sendo verificado.
    RateLimiter::clear('pwd-reset:dono@loja-a.com:loja-a');

    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'dono@loja-a.com',
        'password' => Hash::make('senha-antiga'),
    ]);
});

/** Extrai o token em texto puro do e-mail enviado. */
function capturedToken(): string
{
    $mail = null;
    Mail::assertSent(PasswordResetLink::class, function ($m) use (&$mail) {
        $mail = $m;

        return true;
    });

    return $mail->token;
}

it('envia o link de redefinição para uma conta existente', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
    ])->assertOk();

    Mail::assertSent(PasswordResetLink::class, fn ($m) => $m->hasTo('dono@loja-a.com'));

    // O token é guardado com hash, como uma senha: um dump do banco não pode
    // permitir assumir contas.
    $record = DB::table('password_reset_tokens')->where('email', 'dono@loja-a.com')->first();
    expect($record)->not->toBeNull()
        ->and($record->tenant_id)->toBe($this->tenant->id)
        ->and($record->token)->not->toBe(capturedToken());
});

/*
 * Não confirmar nem negar a existência da conta.
 *
 * Uma resposta diferente para e-mail inexistente permitiria enumerar quem
 * trabalha em qual loja — e é o que transforma um vazamento de senhas de outro
 * site em ataque direcionado aqui.
 */
it('responde igual para e-mail que não existe', function () {
    Mail::fake();

    $conhecido = $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ]);

    RateLimiter::clear('pwd-reset:ninguem@lugar.com:loja-a');

    $desconhecido = $this->postJson('/api/auth/forgot-password', [
        'email' => 'ninguem@lugar.com', 'tenant' => 'loja-a',
    ]);

    expect($desconhecido->json('message'))->toBe($conhecido->json('message'));
    Mail::assertSent(PasswordResetLink::class, 1);
});

it('redefine a senha com um token válido', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ])->assertOk();

    $this->postJson('/api/auth/reset-password', [
        'token' => capturedToken(),
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
        'password' => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ])->assertOk();

    expect(Hash::check('senha-nova-123', $this->user->fresh()->password))->toBeTrue();

    // Consumido: o link não serve duas vezes.
    expect(DB::table('password_reset_tokens')->where('email', 'dono@loja-a.com')->exists())
        ->toBeFalse();
});

it('recusa um token que já foi usado', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ]);

    $payload = [
        'token' => capturedToken(),
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
        'password' => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ];

    $this->postJson('/api/auth/reset-password', $payload)->assertOk();
    $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
});

it('recusa um token forjado', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ]);

    $this->postJson('/api/auth/reset-password', [
        'token' => str_repeat('a', 64),
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
        'password' => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ])->assertStatus(422);

    expect(Hash::check('senha-antiga', $this->user->fresh()->password))->toBeTrue();
});

it('recusa um token expirado', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ]);

    $token = capturedToken();

    DB::table('password_reset_tokens')
        ->where('email', 'dono@loja-a.com')
        ->update(['created_at' => now()->subMinutes(61)]);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
        'password' => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ])->assertStatus(422);

    expect(Hash::check('senha-antiga', $this->user->fresh()->password))->toBeTrue();
});

/*
 * O isolamento que motivou a migration.
 *
 * `users` é único por (tenant_id, email), então o mesmo endereço pode ser dono
 * de uma pizzaria e gerente de um sushi. Com a chave primária só no e-mail —
 * como o Laravel cria a tabela — o token de uma loja trocaria a senha da outra.
 */
it('não aceita o token de uma loja para redefinir a senha em outra', function () {
    Mail::fake();

    $outra = Tenant::factory()->create(['slug' => 'loja-b']);
    $mesmoEmail = User::factory()->create([
        'tenant_id' => $outra->id,
        'email' => 'dono@loja-a.com',
        'password' => Hash::make('senha-da-loja-b'),
    ]);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ])->assertOk();

    $this->postJson('/api/auth/reset-password', [
        'token' => capturedToken(),
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-b',
        'password' => 'invadida-123',
        'password_confirmation' => 'invadida-123',
    ])->assertStatus(422);

    expect(Hash::check('senha-da-loja-b', $mesmoEmail->fresh()->password))->toBeTrue();
});

it('mantém os pedidos das duas lojas vivos ao mesmo tempo', function () {
    Mail::fake();

    $outra = Tenant::factory()->create(['slug' => 'loja-b']);
    User::factory()->create(['tenant_id' => $outra->id, 'email' => 'dono@loja-a.com']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'dono@loja-a.com', 'tenant' => 'loja-a']);
    RateLimiter::clear('pwd-reset:dono@loja-a.com:loja-b');
    $this->postJson('/api/auth/forgot-password', ['email' => 'dono@loja-a.com', 'tenant' => 'loja-b']);

    // Antes da chave composta, o segundo pedido sobrescrevia o primeiro e o
    // lojista da loja-a recebia um link já morto, sem explicação.
    expect(DB::table('password_reset_tokens')->where('email', 'dono@loja-a.com')->count())
        ->toBe(2);
});

/*
 * Redefinir senha é o que se faz ao suspeitar de acesso indevido. Manter as
 * sessões antigas válidas deixaria o invasor dentro do painel justamente
 * depois da ação tomada para expulsá-lo.
 */
it('encerra as sessões abertas ao redefinir a senha', function () {
    Mail::fake();

    Sanctum::actingAs($this->user);
    $this->user->createToken('sessao-antiga');
    expect($this->user->tokens()->count())->toBeGreaterThan(0);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ]);

    $this->postJson('/api/auth/reset-password', [
        'token' => capturedToken(),
        'email' => 'dono@loja-a.com',
        'tenant' => 'loja-a',
        'password' => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ])->assertOk();

    expect($this->user->fresh()->tokens()->count())->toBe(0);
});

it('exige confirmação e tamanho mínimo da senha nova', function () {
    Mail::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ]);

    $token = capturedToken();

    $this->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
        'password' => 'curta', 'password_confirmation' => 'curta',
    ])->assertStatus(422);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
        'password' => 'senha-nova-123', 'password_confirmation' => 'diferente-123',
    ])->assertStatus(422);
});

/*
 * Sem isto o endpoint vira uma metralhadora de e-mail apontada para terceiros:
 * basta repetir o POST para encher a caixa de um lojista.
 */
it('limita pedidos repetidos para a mesma conta', function () {
    Mail::fake();

    foreach (range(1, 3) as $i) {
        $this->postJson('/api/auth/forgot-password', [
            'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
        ])->assertOk();
    }

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'dono@loja-a.com', 'tenant' => 'loja-a',
    ])->assertStatus(422);

    Mail::assertSent(PasswordResetLink::class, 3);
});
