<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetLink;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Redefinição de senha do painel do lojista.
 *
 * Escrito à mão em vez de usar o `Password` broker do Laravel porque o broker
 * casa o token apenas pelo e-mail, e aqui `users` é único por
 * (tenant_id, email) — o mesmo endereço pode ser dono de uma pizzaria e
 * gerente de um sushi. Um token emitido para uma loja não pode trocar a senha
 * da outra, e é o par (email, tenant) que identifica a conta.
 *
 * O fluxo tem dois passos, espelhando o login: pedir o link informando a loja,
 * e consumir o token definindo a senha nova.
 */
class PasswordResetController extends Controller
{
    /** Validade do link. Curta o suficiente para limitar o estrago de um
     *  e-mail vazado, longa o bastante para quem só lê e-mail à noite. */
    private const TOKEN_TTL_MINUTES = 60;

    /**
     * Passo 1: gera o token e envia o link.
     *
     * A resposta é sempre a mesma, exista a conta ou não. Confirmar que um
     * e-mail está cadastrado numa loja específica é vazamento de informação:
     * permite enumerar quem trabalha onde, e é o que transforma um vazamento
     * de senhas de outro site em ataque direcionado aqui.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'tenant' => ['required', 'string'],
        ]);

        /*
         * Limite por e-mail+loja, não por IP.
         *
         * Sem isto o endpoint vira uma metralhadora de e-mail apontada para
         * terceiros: basta repetir o POST para encher a caixa de um lojista.
         * A chave inclui a loja porque o par é o que identifica a conta.
         */
        $email = Str::lower($data['email']);
        $tenantSlug = Str::lower($data['tenant']);
        $key = 'pwd-reset:'.$email.':'.$tenantSlug;

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Aguarde alguns minutos antes de pedir outro link.',
            ]);
        }

        RateLimiter::hit($key, decaySeconds: 900);

        $tenant = Tenant::where('slug', $tenantSlug)->first();

        $user = $tenant
            ? User::where('tenant_id', $tenant->id)->where('email', $email)->first()
            : null;

        if ($user) {
            $this->sendLink($user, $tenant);
        }

        return response()->json([
            'message' => 'Se houver uma conta com este e-mail, o link de redefinição foi enviado.',
        ]);
    }

    /**
     * Passo 2: consome o token e grava a senha nova.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'tenant' => ['required', 'string'],
            // `confirmed` exige password_confirmation no payload: a senha é
            // digitada às cegas e um typo trancaria o lojista para fora.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = Str::lower($data['email']);
        $tenantSlug = Str::lower($data['tenant']);
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        $user = $tenant
            ? User::where('tenant_id', $tenant->id)->where('email', $email)->first()
            : null;

        $record = $user ? $this->findToken($email, $tenant->id) : null;

        // Mensagem única para token ausente, expirado ou de outra conta: os três
        // casos são indistinguíveis para quem tem direito ao link, e separá-los
        // só ajudaria quem está adivinhando.
        if (! $record || ! Hash::check($data['token'], $record->token)) {
            throw ValidationException::withMessages([
                'token' => 'Este link de redefinição é inválido ou expirou.',
            ]);
        }

        if ($this->expired($record)) {
            $this->forgetToken($data['email'], $tenant->id);

            throw ValidationException::withMessages([
                'token' => 'Este link de redefinição é inválido ou expirou.',
            ]);
        }

        DB::transaction(function () use ($user, $data, $tenant) {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();

            // O token é de uso único: quem já trocou a senha não deve poder
            // trocar de novo com o mesmo link, e um e-mail encaminhado por
            // engano perde o valor.
            $this->forgetToken($user->email, $tenant->id);

            /*
             * Revoga as sessões abertas.
             *
             * Redefinir senha é o que a pessoa faz quando suspeita de acesso
             * indevido. Manter os tokens antigos válidos deixaria o invasor
             * dentro do painel justamente depois da ação tomada para expulsá-lo.
             */
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Senha redefinida. Faça login com a senha nova.']);
    }

    private function sendLink(User $user, Tenant $tenant): void
    {
        // O token viaja em texto puro no e-mail e é guardado com hash, como uma
        // senha: um dump do banco não deve permitir assumir contas.
        $plain = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email, 'tenant_id' => $tenant->id],
            ['token' => Hash::make($plain), 'created_at' => now()],
        );

        Mail::to($user->email)->send(new PasswordResetLink($user, $tenant, $plain));
    }

    private function findToken(string $email, int $tenantId): ?object
    {
        return DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    private function forgetToken(string $email, int $tenantId): void
    {
        DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    /**
     * O token passou da validade?
     *
     * `isPast()` sobre o instante de expiração, e não uma subtração de datas:
     * `diffInMinutes` no Carbon 3 é ORIENTADO — devolve negativo quando o alvo
     * está no passado, que é sempre o caso aqui. Comparar esse valor com o TTL
     * fazia todo token expirado passar como válido, e o teste que pegou isso
     * continua no arquivo justamente para impedir a volta.
     *
     * O `created_at` vem como string do query builder (não há model), daí o
     * parse explícito.
     */
    private function expired(object $record): bool
    {
        return Carbon::parse($record->created_at)
            ->addMinutes(self::TOKEN_TTL_MINUTES)
            ->isPast();
    }
}
