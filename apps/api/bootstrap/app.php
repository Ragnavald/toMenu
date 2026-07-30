<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Sem isto o Laravel ignora X-Forwarded-Proto e gera URLs http:// atrás
         * do túnel — o navegador então barra o conteúdo misto. Não adianta pôr
         * o Cloudflare em "Full" se a aplicação não confia no proxy.
         *
         * A requisição sempre chega pelo cloudflared na rede interna do compose,
         * nunca direto da internet (a porta 80 do nginx escuta em loopback), e
         * confiar na faixa privada dispensa manter a lista de IPs do Cloudflare
         * em dia. `*` (confiar em qualquer origem) deixaria qualquer cliente
         * capaz de forjar o esquema e o IP de origem.
         */
        $middleware->trustProxies(
            at: ['127.0.0.1', '172.16.0.0/12'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'identify.tenant' => IdentifyTenant::class,
            'tenant.member' => EnsureUserBelongsToTenant::class,
        ]);

        // Webhooks são autenticados por assinatura HMAC do provedor, não por
        // sessão. Manter CSRF aqui apenas quebraria a entrega.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);

        /*
         * Não redirecionar visitantes não autenticados em rotas de API.
         *
         * O middleware Authenticate tenta `route('login')` antes de qualquer
         * exception handler. Como não existe rota `login` numa API stateless,
         * o resultado era um 500 "Route [login] not defined" em vez de 401 —
         * e só aparecia quando o cliente omitia o header Accept, o que torna
         * o bug fácil de não notar em teste.
         *
         * Retornar null aqui faz o Laravel lançar AuthenticationException,
         * que o handler abaixo converte em 401.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Sem isto, o handler padrão tenta redirecionar para a rota nomeada
        // `login` — que não existe numa API stateless — e o cliente recebe um
        // 500 confuso em vez do 401 que deveria tratar.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }
        });
    })->create();
