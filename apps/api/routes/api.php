<?php

use App\Http\Controllers\Admin\CategoryAdminController;
use App\Http\Controllers\Admin\FinanceAdminController;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Http\Controllers\Admin\ProductAdminController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoreDeletionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Central\RegistrationController;
use App\Http\Controllers\Storefront\MenuController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

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
|--------------------------------------------------------------------------
| Storefront — público, tenant-scoped
|--------------------------------------------------------------------------
*/
Route::middleware(['identify.tenant'])->group(function () {
    Route::get('menu', MenuController::class)->middleware('throttle:240,1');

    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('throttle:20,1'); // Limite mais baixo: cria registro e cobra.

    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->middleware('throttle:60,1');
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

        // A reordenação e criação em lote vêm antes do apiResource para que não sejam
        // capturados como {category} pelo route model binding.
        Route::post('categories/reorder', [CategoryAdminController::class, 'reorder']);
        Route::post('categories/batch', [CategoryAdminController::class, 'batchStore']);
        Route::apiResource('categories', CategoryAdminController::class)
            ->only(['index', 'store', 'update', 'destroy']);

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

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings/profile', [SettingsController::class, 'updateProfile']);
        Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::put('settings/delivery', [SettingsController::class, 'updateDelivery']);
        Route::put('settings/hours', [SettingsController::class, 'updateHours']);
        Route::put('settings/payments', [SettingsController::class, 'updatePayments']);
        Route::put('settings/theme', [SettingsController::class, 'updateTheme']);
        Route::put('settings/onboarding', [SettingsController::class, 'updateOnboarding']);

        // Exclusão da loja. Só o owner; o controller ainda exige senha e o slug
        // digitado. O throttle limita tentativa de adivinhar a senha por aqui.
        Route::get('store/deletion-preview', [StoreDeletionController::class, 'preview']);
        Route::delete('store', [StoreDeletionController::class, 'destroy'])
            ->middleware('throttle:5,1');
    });
