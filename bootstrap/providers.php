<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PaymentGatewayServiceProvider;
use App\Providers\RegistrarServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    PaymentGatewayServiceProvider::class,
    RegistrarServiceProvider::class,
];
