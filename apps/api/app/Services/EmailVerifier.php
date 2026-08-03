<?php

namespace App\Services;

use App\Mail\VerifyEmailLink;
use App\Mail\WelcomeStoreOwner;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Emissão e consumo do token de confirmação de e-mail.
 *
 * Serviço e não método do controller porque o cadastro (TenantRegistrar)
 * também emite o primeiro link, e as duas origens precisam gravar o token do
 * mesmo jeito — se divergirem, o link do cadastro deixa de casar com a
 * validação do reenvio.
 */
class EmailVerifier
{
    /**
     * Validade do link.
     *
     * Mais curta que a da redefinição de senha (60 min) porque a janela aqui
     * é outra: o lojista acabou de submeter o cadastro e está parado na tela
     * esperando o e-mail, então 30 minutos cobrem folgadamente o atraso de
     * entrega sem manter um link de acesso vivo por uma hora. Quem passar
     * disso pede outro pelo botão de reenvio.
     */
    public const TOKEN_TTL_MINUTES = 30;

    /** Gera um token novo, invalida o anterior e envia o link. */
    public function sendLink(User $user, Tenant $tenant): void
    {
        // O token viaja em texto puro no e-mail e é guardado com hash, como uma
        // senha: um dump do banco não deve permitir confirmar contas alheias.
        $plain = Str::random(64);

        // `updateOrInsert` na chave `user_id`: pedir um link novo invalida o
        // anterior, para que um e-mail antigo encaminhado por engano não sirva.
        DB::table('email_verification_tokens')->updateOrInsert(
            ['user_id' => $user->id],
            ['token' => Hash::make($plain), 'created_at' => now()],
        );

        Mail::to($user->email)->send(
            new VerifyEmailLink($user, $tenant, $plain, self::TOKEN_TTL_MINUTES),
        );
    }

    /**
     * Valida o token e, se confere, marca a conta como confirmada.
     *
     * Devolve false para token ausente, errado ou expirado — o controller
     * traduz os três na mesma mensagem, porque distingui-los só ajuda quem
     * está adivinhando.
     */
    public function consume(User $user, string $plainToken): bool
    {
        $record = DB::table('email_verification_tokens')
            ->where('user_id', $user->id)
            ->first();

        if (! $record || ! Hash::check($plainToken, $record->token)) {
            return false;
        }

        if ($this->expired($record)) {
            // Token vencido sai da tabela: deixá-lo ali só acumularia linha
            // morta, e o reenvio grava outro por cima de qualquer forma.
            $this->forget($user);

            return false;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['email_verified_at' => now()])->save();

            // Uso único: um link encaminhado por engano não pode continuar
            // valendo depois que a conta já foi confirmada.
            $this->forget($user);
        });

        $this->sendWelcome($user);

        return true;
    }

    /**
     * Boas-vindas, agora que a conta está de fato utilizável.
     *
     * Saía do cadastro antes da confirmação existir. Ficaria errado ali: o
     * e-mail diz "sua loja está no ar" e leva ao painel, que estaria trancado
     * — duas chamadas para ação concorrentes na mesma caixa de entrada, e a
     * mais convidativa levando a uma porta fechada.
     *
     * Continua ShouldQueue e por isso segue em try/catch: a confirmação já
     * está gravada neste ponto, e derrubar a resposta por causa de um e-mail
     * de cortesia negaria a sessão a quem clicou num link válido — o token já
     * foi consumido e o segundo clique não funcionaria.
     */
    private function sendWelcome(User $user): void
    {
        try {
            Mail::to($user->email)->send(
                new WelcomeStoreOwner($user, $user->tenant),
            );
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar o e-mail de boas-vindas.', [
                'tenant_id' => $user->tenant_id,
                'exception' => $e,
            ]);
        }
    }

    private function forget(User $user): void
    {
        DB::table('email_verification_tokens')->where('user_id', $user->id)->delete();
    }

    /**
     * O token passou da validade?
     *
     * `isPast()` sobre o instante de expiração, e não uma subtração de datas:
     * `diffInMinutes` no Carbon 3 é ORIENTADO — devolve negativo quando o alvo
     * está no passado, que é sempre o caso aqui —, e comparar esse valor com o
     * TTL faria todo token expirado passar como válido. O mesmo erro já foi
     * cometido no fluxo de senha; o comentário fica nos dois lugares.
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
