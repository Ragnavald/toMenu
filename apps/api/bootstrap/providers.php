<?php

use App\Providers\AppServiceProvider;
use App\Providers\StripeServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    StripeServiceProvider::class,
];
