<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porta única do painel da plataforma.
 *
 * As rotas sob /api/platform operam sobre TODAS as lojas — listam, suspendem,
 * purgam e emitem token de acesso ao painel alheio. Nenhuma delas passa por
 * identify.tenant, então o EnsureUserBelongsToTenant não as protege: esta é a
 * única checagem entre um token Sanctum qualquer e o controle da plataforma
 * inteira.
 *
 * O token do lojista é um token Sanctum válido como qualquer outro. Sem este
 * middleware, o dono de uma pizzaria chamaria /api/platform/stores e receberia
 * a lista completa de concorrentes — e o endpoint de purga.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);

        // isPlatformAdmin exige as duas condições (sem tenant E com a flag).
        abort_unless($user->isPlatformAdmin(), 403, 'Acesso restrito à equipe da plataforma.');

        /*
         * Um token de impersonação nunca pode reentrar aqui.
         *
         * Ele é emitido para o owner da loja, que não é staff — a checagem
         * acima já barraria. A verificação existe para o caso de o staff
         * impersonar a si mesmo por engano de configuração, e para que a regra
         * fique explícita: token com a habilidade de impersonação é sempre de
         * escopo de loja, jamais de plataforma.
         */
        abort_if(
            $user->currentAccessToken()?->can('impersonate') === true,
            403,
            'Token de impersonação não opera o painel da plataforma.',
        );

        return $next($request);
    }
}
