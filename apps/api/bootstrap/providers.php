<?php

use App\Providers\AppServiceProvider;
use App\Providers\SentryContextProvider;
use App\Providers\StripeServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    StripeServiceProvider::class,
    SentryContextProvider::class,
];
