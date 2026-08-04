<?php

use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CategoryAdminController;
use App\Http\Controllers\Admin\FinanceAdminController;
use App\Http\Controllers\Admin\MenuImportController;
use App\Http\Controllers\Admin\ModifierGroupAdminController;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Http\Controllers\Admin\ProductAdminController;
use App\Http\Controllers\Admin\QrCodeController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoreDeletionController;
use App\Http\Controllers\Auth\EmailVerificationController;
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
 * Confirmação do e-mail do cadastro.
 *
 * Mesma divisão do fluxo de senha, e pelos mesmos motivos: o `resend` dispara
 * e-mail para terceiros e soma um limite por conta (no controller) ao limite
 * por IP daqui; o `verify` não leva Turnstile porque quem chega nele veio de um
 * link na própria caixa de entrada, e um desafio ali só atrapalharia quem já
 * provou ter acesso a ela.
 *
 * O `resend` também fica sem Turnstile, ao contrário do forgot-password: ele é
 * chamado pela tela de confirmação logo após o cadastro, onde o widget acabou
 * de ser resolvido e gasto no POST /register — montar um segundo desafio no
 * mesmo minuto pediria ao lojista que provasse duas vezes ser humano. O limite
 * por conta é o que segura o abuso aqui.
 */
Route::post('auth/verify-email', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:10,1');

Route::post('auth/verify-email/resend', [EmailVerificationController::class, 'resend'])
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
/*
 * O curinga não pode engolir os prefixos da própria plataforma.
 *
 * `{tenant}` casa com qualquer primeiro segmento, inclusive `admin` e
 * `platform`, e por ser registrado antes daqueles grupos ganha deles no
 * roteamento. Uma chamada a `/api/admin/orders/archived` era resolvida como
 * `{tenant}=admin`, `{order}=archived` — e como `admin` é subdomínio reservado
 * no IdentifyTenant, a resposta era 404 "Loja não encontrada" em vez da rota
 * de admin pretendida.
 *
 * O sintoma engana: só aparece em rota de admin com DOIS segmentos depois do
 * prefixo (`orders/archived`), a única forma que casa com `{tenant}/orders/{order}`.
 * `/api/admin/orders` sozinho não casa com nada aqui e sempre funcionou, o que
 * fazia o erro parecer específico da rota nova.
 *
 * A restrição resolve na origem, e não pela ordem de registro: nenhum slug de
 * loja pode ser `admin` ou `platform` de qualquer forma — RESERVED no
 * IdentifyTenant já os recusa —, então excluí-los aqui não tira nenhum tenant
 * alcançável e protege toda rota de plataforma que venha a existir depois.
 */
Route::prefix('{tenant}')
    /*
     * O lookahead não pode usar `$`: o Laravel embute esta regex no meio do
     * padrão da rota completa, onde o fim do segmento ainda não é o fim da
     * string. `(?!admin$)` casaria só se `admin` fosse o path inteiro, e
     * `/api/admin/orders/archived` passaria batido — que era exatamente a
     * falha. `(?!(admin|platform)(/|$))` ancora no separador de segmento.
     */
    ->where(['tenant' => '(?!(?:admin|platform)(?:/|$))[a-z0-9-]+'])
    ->middleware(['identify.tenant'])
    ->group(function () {
        Route::get('menu', MenuController::class)->middleware('throttle:240,1');

        /*
         * O pedido também precisa do slug no path, e não só o cardápio.
         *
         * O storefront chama a API pelo browser em NEXT_PUBLIC_API_URL, que em
         * produção é `api.{dominio}` — subdomínio RESERVADO no IdentifyTenant.
         * O branch de subdomínio o recusa de propósito, X-Tenant é ignorado
         * (TENANCY_TRUST_HEADER=false) e a rota sem `{tenant}` não tinha um
         * terceiro caminho: todo pedido em produção respondia 404 "Loja não
         * encontrada", no último passo do checkout.
         *
         * O mesmo vale em desenvolvimento no acesso por caminho
         * (localhost:3000/loja), onde o Host é o domínio raiz e não um
         * subdomínio de um nível.
         *
         * `plan.orders` e os throttles acompanham as rotas: sem repeti-los aqui
         * o path viraria um desvio silencioso do gate de plano e dos limites.
         */
        Route::post('orders', [OrderController::class, 'store'])
            ->middleware(['plan.orders', 'throttle:20,1']);

        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->middleware(['plan.orders', 'throttle:60,1']);
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

        // Modelo de cardápio pronto: categorias + produtos + grupos numa
        // transação só. O `categories/batch` acima cria apenas seções vazias.
        Route::post('menu/import', [MenuImportController::class, 'store']);
        Route::apiResource('categories', CategoryAdminController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Operação e dinheiro só existem onde há pedido. No plano
        // somente-cardápio estas telas não aparecem no painel, e o
        // `plan.orders` garante que também não respondam a chamada direta.
        Route::middleware('plan.orders')->group(function () {
            Route::get('orders', [OrderAdminController::class, 'index']);
            // Antes de `orders/{order}`: o segmento literal precisa ganhar do
            // parâmetro, senão "archived" seria lido como id de pedido.
            Route::get('orders/archived', [OrderAdminController::class, 'archived']);
            // Apaga linhas em lote e não tem volta — throttle baixo pelo mesmo
            // motivo do export: não é chamada de rajada.
            Route::delete('orders/archived', [OrderAdminController::class, 'clearArchived'])
                ->middleware('throttle:10,1');
            Route::patch('orders/{order}/status', [OrderAdminController::class, 'updateStatus']);
            Route::patch('orders/{order}/archive', [OrderAdminController::class, 'archive']);

            Route::get('finance/overview', [FinanceAdminController::class, 'overview']);
            Route::get('finance/orders', [FinanceAdminController::class, 'orders']);
            // CSV em streaming, direto na resposta. GET e não POST porque nada
            // é criado: o navegador baixa pela própria navegação, sem fila,
            // sem registro e sem arquivo em disco.
            Route::get('finance/export', [FinanceAdminController::class, 'export'])
                ->middleware('throttle:20,1'); // Consulta longa; não é para disparar em rajada.

            Route::put('settings/delivery', [SettingsController::class, 'updateDelivery']);
            Route::put('settings/payments', [SettingsController::class, 'updatePayments']);
        });

        /*
         * Assinatura da loja na plataforma.
         *
         * FORA do grupo `plan.orders`: o plano somente-cardápio também é pago,
         * e travar a cobrança atrás da capacidade de pedidos impediria
         * justamente a loja mais barata de assinar.
         *
         * O throttle do checkout é baixo porque cada chamada cria sessão (e,
         * na primeira vez, um cliente) no Stripe.
         */
        Route::get('billing', [BillingController::class, 'show']);
        Route::post('billing/checkout', [BillingController::class, 'checkout'])
            ->middleware('throttle:10,1');

        /*
         * Validação do cupom digitado.
         *
         * Throttle mais apertado que os demais: o endpoint responde se um
         * código existe ou não, o que o torna a superfície natural para varrer
         * cupons por tentativa. 20/min ainda é folgado para quem digita um
         * código de verdade.
         */
        Route::post('billing/coupon', [BillingController::class, 'coupon'])
            ->middleware('throttle:20,1');
        Route::post('billing/portal', [BillingController::class, 'portal'])
            ->middleware('throttle:10,1');

        /*
         * QR Code do endereço da loja.
         *
         * FORA do grupo `plan.orders`, pelo mesmo motivo do billing: o QR abre
         * o cardápio, que os dois planos têm. Travá-lo atrás da capacidade de
         * pedidos tiraria o recurso justamente de quem depende dele para levar
         * o cliente da mesa ao cardápio.
         *
         * O throttle é folgado porque a resposta quase sempre sai do cache (ou
         * nem chega a sair, quando o navegador revalida por ETag); serve só
         * para conter a geração em rajada de tamanhos diferentes.
         */
        Route::get('store/qrcode', [QrCodeController::class, 'show'])
            ->middleware('throttle:60,1');

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings/profile', [SettingsController::class, 'updateProfile']);
        Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::post('settings/cover', [SettingsController::class, 'uploadCover']);
        Route::put('settings/hours', [SettingsController::class, 'updateHours']);
        Route::put('settings/theme', [SettingsController::class, 'updateTheme']);
        Route::put('settings/onboarding', [SettingsController::class, 'updateOnboarding']);

        // Exclusão da loja. Só o owner; o controller ainda exige senha e o slug
        // digitado. O throttle limita tentativa de adivinhar a senha por aqui.
        Route::get('store/deletion-preview', [StoreDeletionController::class, 'preview']);
        Route::delete('store', [StoreDeletionController::class, 'destroy'])
            ->middleware('throttle:5,1');
    });
