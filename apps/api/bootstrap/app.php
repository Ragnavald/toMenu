<?php

use App\Http\Middleware\EnsurePlanAllowsOrders;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\VerifyTurnstile;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Sem isto o Laravel ignora X-Forwarded-Proto e gera URLs http:// atrás
         * do proxy — o navegador então barra o conteúdo misto.
         *
         * Quem fala com o PHP-FPM é sempre o nginx, pela rede interna do
         * compose; o TLS e o contato com a internet terminam nele. Por isso a
         * faixa privada basta aqui, e a lista de IPs do Cloudflare fica onde ela
         * de fato importa (nginx e ufw). `*` (confiar em qualquer origem)
         * deixaria qualquer cliente capaz de forjar o esquema e o IP de origem.
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
            'platform.admin' => EnsurePlatformAdmin::class,
            'plan.orders' => EnsurePlanAllowsOrders::class,
            'turnstile' => VerifyTurnstile::class,
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
        /*
         * Relata exceções ao Sentry.
         *
         * Sem DSN configurado isto é inerte — nada é enviado e nada quebra —,
         * então desenvolvimento e a suíte de testes seguem sem tocar na rede.
         *
         * O que NÃO é relatado está na lista `ignore_exceptions` de
         * `config/sentry.php`: 404, 422, 401 e afins são respostas normais de
         * uma API pública, e deixá-las passar afogaria o alerta que importa em
         * ruído de gente digitando URL errada.
         */
        Integration::handles($exceptions);

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
