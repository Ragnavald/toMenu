<?php

namespace App\Providers;

use App\Services\StripeMode;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeMode::class);

        /*
         * A chave vem do modo ativo (test|live), resolvido pelo StripeMode.
         *
         * A validação de credencial acontece aqui, na resolução, e não no boot
         * da aplicação: TenantDeleter e TenantPurger resolvem este binding sob
         * demanda justamente para que um ambiente sem Stripe (dev, CI) continue
         * subindo e excluindo lojas. Validar no boot quebraria esses ambientes;
         * validar aqui só afeta quem de fato vai falar com o Stripe.
         */
        $this->app->singleton(StripeClient::class, function ($app) {
            return new StripeClient([
                'api_key' => $app->make(StripeMode::class)->secretKey(),
                'stripe_version' => '2024-06-20',
            ]);
        });
    }
}
