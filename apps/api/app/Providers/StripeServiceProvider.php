<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn () => new StripeClient([
            'api_key' => config('services.stripe.secret'),
            'stripe_version' => '2024-06-20',
        ]));
    }
}
