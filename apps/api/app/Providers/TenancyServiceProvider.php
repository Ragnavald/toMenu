<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\TenantSettings;
use App\Observers\InvalidatesMenuCache;
use App\Tenancy\PendingMenuInvalidations;
use App\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

class TenancyServiceProvider extends ServiceProvider
{
    /** Models cuja alteração torna o cardápio cacheado obsoleto. */
    private const MENU_MODELS = [
        Product::class,
        Category::class,
        ModifierGroup::class,
        Modifier::class,
        TenantSettings::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(PendingMenuInvalidations::class);
    }

    public function boot(): void
    {
        foreach (self::MENU_MODELS as $model) {
            $model::observe(InvalidatesMenuCache::class);
        }

        $this->registerMenuInvalidationFlush();
        $this->registerOctaneReset();
    }

    /**
     * Drena as invalidações de cardápio acumuladas na request.
     *
     * `terminating` roda depois da resposta já ter sido enviada ao cliente, o
     * que é deliberado em dois sentidos:
     *
     * 1. O bump fica FORA da transação do save. Dentro dela, o UPDATE em
     *    `tenants` mantinha a linha travada até o commit — a mesma linha que o
     *    OrderNumberGenerator precisa para numerar um pedido. Fora, a versão
     *    sobe alguns milissegundos depois do commit, e nesse intervalo uma
     *    leitura ainda serve o cardápio anterior. É uma defasagem muito menor
     *    que o `s-maxage=60` do cache HTTP, que o sistema já aceita por design.
     *
     * 2. O lojista não espera pelo trabalho. O UPDATE e o dispatch do purge
     *    saem do tempo de resposta do "Salvar".
     *
     * Comandos de console e seeders já saem cobertos: o Kernel do console
     * chama `terminate()` ao fim da execução. Workers de fila não — o processo
     * é de vida longa e só terminaria ao ser reciclado, então cada job é
     * drenado individualmente. Sem isso um job que edita o cardápio acumularia
     * a invalidação sem nunca aplicá-la, e o cache serviria dado obsoleto até o
     * TTL — exatamente a falha que o observer existe para impedir.
     */
    private function registerMenuInvalidationFlush(): void
    {
        $this->app->terminating(function () {
            $this->app->make(PendingMenuInvalidations::class)->flush();
        });

        // Um job que falha já teve suas escritas revertidas ou não — de todo
        // modo, o que ele chegou a gravar precisa invalidar o cache. Drenar nos
        // dois eventos evita que a lista vaze para o job seguinte do mesmo
        // worker, que é um processo de vida longa.
        $this->app['events']->listen(
            [JobProcessed::class, JobFailed::class],
            fn () => $this->app->make(PendingMenuInvalidations::class)->flush(),
        );
    }

    /**
     * Sob Octane o worker persiste entre requests. Sem este reset, o tenant da
     * request anterior continuaria no container e vazaria para a próxima — o
     * bug mais grave possível nesta arquitetura, e silencioso.
     *
     * O singleton já é recriado a cada request pelo container do Octane; isto
     * garante também a limpeza da variável de sessão do Postgres, que vive na
     * conexão e não no container.
     */
    private function registerOctaneReset(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        $this->app['events']->listen(
            RequestReceived::class,
            fn () => app(TenantContext::class)->forget(),
        );
    }
}
