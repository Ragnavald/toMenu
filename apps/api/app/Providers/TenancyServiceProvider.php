<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\TenantSettings;
use App\Observers\InvalidatesMenuCache;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
        foreach (self::MENU_MODELS as $model) {
            $model::observe(InvalidatesMenuCache::class);
        }

        $this->registerOctaneReset();
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
        if (! class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }

        $this->app['events']->listen(
            \Laravel\Octane\Events\RequestReceived::class,
            fn () => app(TenantContext::class)->forget(),
        );
    }
}
