<?php

use App\Http\Controllers\Admin\CategoryAdminController;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Http\Controllers\Admin\ProductAdminController;
use App\Http\Controllers\Admin\SettingsController;
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
    ->middleware('throttle:10,1');

Route::get('register/check-slug', [RegistrationController::class, 'checkSlug'])
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Autenticação
|--------------------------------------------------------------------------
*/
Route::post('auth/login', [LoginController::class, 'login'])
    ->middleware('throttle:5,1'); // Rate limit apertado: superfície de brute force.

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

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings/profile', [SettingsController::class, 'updateProfile']);
        Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::put('settings/delivery', [SettingsController::class, 'updateDelivery']);
        Route::put('settings/hours', [SettingsController::class, 'updateHours']);
        Route::put('settings/payments', [SettingsController::class, 'updatePayments']);
        Route::put('settings/theme', [SettingsController::class, 'updateTheme']);
        Route::put('settings/onboarding', [SettingsController::class, 'updateOnboarding']);
    });
