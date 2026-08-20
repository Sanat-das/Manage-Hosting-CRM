<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PaymentGatewayServiceProvider;
use App\Providers\RegistrarServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    FortifyServiceProvider::class,
    PaymentGatewayServiceProvider::class,
    RegistrarServiceProvider::class,
];
