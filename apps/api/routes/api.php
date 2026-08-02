<?php

use App\Http\Controllers\Admin\CategoryAdminController;
use App\Http\Controllers\Admin\FinanceAdminController;
use App\Http\Controllers\Admin\ModifierGroupAdminController;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Http\Controllers\Admin\ProductAdminController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoreDeletionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Central\RegistrationController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\StoreManagementController;
use App\Http\Controllers\Storefront\MenuController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autorização dos canais privados de WebSocket
|--------------------------------------------------------------------------
| O painel é um SPA que autentica por token Bearer, não por cookie de sessão.
| A rota padrão do Laravel (`/broadcasting/auth`, no grupo `web`) usaria a
| sessão e responderia 403 para o painel; por isso ela é registrada aqui, sob
| `auth:sanctum`, e o Echo aponta para `/api/broadcasting/auth`.
|
| Quem decide o acesso é routes/channels.php.
*/
Broadcast::routes(['middleware' => ['auth:sanctum']]);

/*
|--------------------------------------------------------------------------
| Webhooks — sem contexto de tenant
|--------------------------------------------------------------------------
| Chamados pelo Stripe no domínio da plataforma. Autenticados por assinatura
| HMAC, não por sessão; o tenant é extraído do metadata do evento.
*/
Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| Central — cadastro de novas lojas
|--------------------------------------------------------------------------
| Fora do escopo de tenant por definição: a loja está sendo criada agora.
*/
Route::post('register', [RegistrationController::class, 'store'])
    ->middleware(['throttle:10,1', 'turnstile']);

Route::get('register/check-slug', [RegistrationController::class, 'checkSlug'])
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Autenticação
|--------------------------------------------------------------------------
*/
// Rate limit apertado + Turnstile: as duas defesas da superfície de brute
// force. O limite por IP não basta sozinho — trocar de IP é barato para quem
// automatiza — e o Turnstile não basta sozinho porque cada token é uma
// tentativa legítima a mais.
Route::post('auth/login', [LoginController::class, 'login'])
    ->middleware(['throttle:5,1', 'turnstile']);

Route::post('auth/logout', [LoginController::class, 'logout'])
    ->middleware('auth:sanctum');

/*
 * Redefinição de senha.
 *
 * Mesmas defesas do login, e pelo mesmo motivo: os dois endpoints aceitam
 * e-mail de qualquer origem e disparam trabalho no servidor. O `request` ainda
 * envia e-mail para terceiros, então soma um limite por conta (no controller)
 * ao limite por IP daqui — sem ele, repetir o POST enche a caixa de um lojista.
 *
 * O `reset` não leva Turnstile: quem chega nele veio de um link no próprio
 * e-mail, e um desafio ali só atrapalharia o lojista que já provou ter acesso
 * à caixa. O throttle por IP continua, contra quem tenta adivinhar token.
 */
Route::post('auth/forgot-password', [PasswordResetController::class, 'request'])
    ->middleware(['throttle:5,1', 'turnstile']);

Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Storefront — público, tenant-scoped
|--------------------------------------------------------------------------
*/
Route::middleware(['identify.tenant'])->group(function () {
    Route::get('menu', MenuController::class)->middleware('throttle:240,1');

    // `plan.orders` recusa a loja no plano somente-cardápio. A UI já não
    // oferece o carrinho ali, mas a rota é pública e adivinhável — esconder
    // não é impedir.
    Route::post('orders', [OrderController::class, 'store'])
        ->middleware(['plan.orders', 'throttle:20,1']); // Limite mais baixo: cria registro e cobra.

    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->middleware(['plan.orders', 'throttle:60,1']);
});

/*
|--------------------------------------------------------------------------
| Plataforma — painel do staff (admin.to-menu.com)
|--------------------------------------------------------------------------
|
| Precisa vir ANTES do grupo `{tenant}` abaixo. Aquele prefixo é um curinga de
| um segmento: registrado primeiro, `/api/platform/stores` casaria com ele,
| `platform` seria interpretado como slug de loja e toda rota daqui responderia
| 404 "Loja não encontrada" — sem nenhuma pista de conflito de rotas.
|
| Nenhuma rota daqui passa por identify.tenant, de propósito: o staff opera
| sobre lojas das quais não é membro, e um tenant no contexto faria o global
| scope e o RLS recortarem as consultas para o tenant errado.
|
| A consequência é que o EnsureUserBelongsToTenant não protege nada aqui —
| `platform.admin` é a única barreira entre um token Sanctum qualquer e o
| controle de todas as lojas.
*/
Route::post('platform/auth/login', [PlatformAuthController::class, 'login'])
    ->middleware(['throttle:5,1', 'turnstile']); // Mesma dupla de defesas do login do lojista.

Route::prefix('platform')
    ->middleware(['auth:sanctum', 'platform.admin'])
    ->group(function () {
        Route::post('auth/logout', [PlatformAuthController::class, 'logout']);

        Route::get('stores', [StoreManagementController::class, 'index']);
        Route::get('stores/{slug}', [StoreManagementController::class, 'show']);

        Route::post('stores/{slug}/suspend', [StoreManagementController::class, 'suspend']);
        Route::post('stores/{slug}/reactivate', [StoreManagementController::class, 'reactivate']);

        // Emite credencial de acesso ao painel de outra loja: o throttle limita
        // o estrago de um token de staff vazado.
        Route::post('stores/{slug}/impersonate', [ImpersonationController::class, 'store'])
            ->middleware('throttle:20,1');
        Route::delete('stores/{slug}/impersonate', [ImpersonationController::class, 'destroy']);

        // Exclusão permanente. O controller ainda exige a senha do staff e o
        // slug digitado; o throttle limita a tentativa de adivinhar a senha.
        Route::get('stores/{slug}/purge-preview', [StoreManagementController::class, 'purgePreview']);
        Route::delete('stores/{slug}', [StoreManagementController::class, 'purge'])
            ->middleware('throttle:5,1');
    });

/*
|--------------------------------------------------------------------------
| Storefront — mesmas rotas, tenant no path
|--------------------------------------------------------------------------
|
| Existe para o render server-side do storefront, que chama a API pela rede
| interna (INTERNAL_API_URL=http://nginx:80). Ali o Host é "nginx", e não dá
| para corrigi-lo pelo header: o `fetch` do Node descarta Host por ser um
| forbidden header, silenciosamente. O resultado seria 404 em toda loja.
|
| X-Tenant não substitui isto — em produção TENANCY_TRUST_HEADER é false,
| porque um tenant escolhido pelo cliente é a falha de isolamento mais grave
| possível. O path param passa pelo mesmo IdentifyTenant e não é forjável por
| quem já não pudesse acessar a loja pelo subdomínio.
|
| O prefixo vem depois do grupo acima para que `/api/menu` continue resolvendo
| pelo subdomínio no acesso do navegador.
*/
Route::prefix('{tenant}')
    ->middleware(['identify.tenant'])
    ->group(function () {
        Route::get('menu', MenuController::class)->middleware('throttle:240,1');
    });

/*
|--------------------------------------------------------------------------
| Admin do tenant — autenticado E com vínculo verificado
|--------------------------------------------------------------------------
| A ordem importa: identify.tenant precisa rodar antes de tenant.member, que
| compara o tenant do usuário com o tenant do contexto.
*/
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'identify.tenant', 'tenant.member'])
    ->group(function () {
        Route::post('products/upload-image', [ProductAdminController::class, 'uploadImage']);
        Route::apiResource('products', ProductAdminController::class);

        // Grupos de opções: borda e adicionais (lista) e sabores (composto).
        // `attach` vem antes do apiResource para não ser lido como {group}.
        Route::post('modifier-groups/{group}/products', [ModifierGroupAdminController::class, 'attach']);
        Route::apiResource('modifier-groups', ModifierGroupAdminController::class)
            ->parameter('modifier-groups', 'group');

        // A reordenação e criação em lote vêm antes do apiResource para que não sejam
        // capturados como {category} pelo route model binding.
        Route::post('categories/reorder', [CategoryAdminController::class, 'reorder']);
        Route::post('categories/batch', [CategoryAdminController::class, 'batchStore']);
        Route::apiResource('categories', CategoryAdminController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Operação e dinheiro só existem onde há pedido. No plano
        // somente-cardápio estas telas não aparecem no painel, e o
        // `plan.orders` garante que também não respondam a chamada direta.
        Route::middleware('plan.orders')->group(function () {
            Route::get('orders', [OrderAdminController::class, 'index']);
            Route::patch('orders/{order}/status', [OrderAdminController::class, 'updateStatus']);

            // Financeiro. As rotas de exportação vêm antes de {report} para não
            // serem capturadas pelo route model binding.
            Route::get('finance/overview', [FinanceAdminController::class, 'overview']);
            Route::get('finance/orders', [FinanceAdminController::class, 'orders']);
            Route::get('finance/exports', [FinanceAdminController::class, 'exports']);
            Route::post('finance/exports', [FinanceAdminController::class, 'requestExport'])
                ->middleware('throttle:20,1'); // Cada chamada enfileira trabalho pesado.
            Route::get('finance/exports/{report}', [FinanceAdminController::class, 'showExport']);
            Route::get('finance/exports/{report}/download', [FinanceAdminController::class, 'download']);

            Route::put('settings/delivery', [SettingsController::class, 'updateDelivery']);
            Route::put('settings/payments', [SettingsController::class, 'updatePayments']);
        });

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings/profile', [SettingsController::class, 'updateProfile']);
        Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::put('settings/hours', [SettingsController::class, 'updateHours']);
        Route::put('settings/theme', [SettingsController::class, 'updateTheme']);
        Route::put('settings/onboarding', [SettingsController::class, 'updateOnboarding']);

        // Exclusão da loja. Só o owner; o controller ainda exige senha e o slug
        // digitado. O throttle limita tentativa de adivinhar a senha por aqui.
        Route::get('store/deletion-preview', [StoreDeletionController::class, 'preview']);
        Route::delete('store', [StoreDeletionController::class, 'destroy'])
            ->middleware('throttle:5,1');
    });
