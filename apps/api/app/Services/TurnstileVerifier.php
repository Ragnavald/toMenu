<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Valida o token do Cloudflare Turnstile contra o siteverify.
 *
 * O widget no navegador não decide nada sozinho: ele só produz um token, e o
 * token só vale depois de confirmado aqui, com o secret que nunca sai do
 * servidor. Sem esta chamada, qualquer cliente poderia inventar o campo.
 */
class TurnstileVerifier
{
    private const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Verificação desligada quando não há secret configurado.
     *
     * É o caso do ambiente local e da suíte de testes, que não têm chaves da
     * Cloudflare. Em produção o secret é obrigatório — está documentado no
     * DEPLOY.md — e sem ele o widget nem seria renderizado no frontend.
     */
    public function isEnabled(): bool
    {
        return filled(config('services.turnstile.secret'));
    }

    /**
     * Um token só é aceito uma vez: o siteverify recusa o reenvio do mesmo
     * token, o que já impede o replay sem precisarmos guardar estado aqui.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(config('services.turnstile.timeout'))
                ->post(self::SITEVERIFY, array_filter([
                    'secret' => config('services.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (ConnectionException $e) {
            /*
             * Cloudflare fora do ar não pode derrubar o login da plataforma
             * inteira. Falhamos aberto de propósito: o custo de recusar todo
             * mundo é maior que o de deixar passar bots durante a indisponi-
             * bilidade, e o rate limit das rotas continua valendo.
             */
            Log::warning('Turnstile indisponível; verificação ignorada.', [
                'erro' => $e->getMessage(),
            ]);

            return true;
        }

        if ($response->failed()) {
            Log::warning('Turnstile respondeu com erro HTTP.', [
                'status' => $response->status(),
            ]);

            return true;
        }

        $success = $response->json('success') === true;

        if (! $success) {
            // Os códigos distinguem token expirado (usuário demorou no
            // formulário) de secret errado — que é erro de deploy, não do
            // usuário, e só aparece aqui.
            Log::info('Turnstile recusou o token.', [
                'codigos' => $response->json('error-codes', []),
            ]);
        }

        return $success;
    }
}
