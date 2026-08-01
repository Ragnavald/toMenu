<?php

namespace App\Http\Middleware;

use App\Services\TurnstileVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige um token válido do Turnstile antes de deixar a requisição seguir.
 *
 * Aplicado ao login e ao cadastro de loja: as duas rotas públicas onde um bot
 * causa dano real — brute force de credenciais numa, criação de lojas em massa
 * na outra. O rate limit por IP continua valendo em cima disto; são defesas
 * complementares, já que trocar de IP é barato para quem automatiza.
 */
class VerifyTurnstile
{
    /** Nome que o widget usa no campo oculto que injeta no formulário. */
    public const FIELD = 'cf-turnstile-response';

    public function __construct(private TurnstileVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verifier->isEnabled()) {
            return $next($request);
        }

        $token = $request->input(self::FIELD);

        if (! $this->verifier->verify($token, $request->ip())) {
            /*
             * ValidationException (422) em vez de 403: o frontend já trata o
             * formato de erros de validação do Laravel, então a mensagem
             * aparece no formulário em vez de virar um erro genérico. A chave
             * é o próprio nome do campo, para o widget poder ser resetado.
             */
            throw ValidationException::withMessages([
                self::FIELD => 'Não foi possível confirmar que você não é um robô. Tente novamente.',
            ]);
        }

        return $next($request);
    }
}
