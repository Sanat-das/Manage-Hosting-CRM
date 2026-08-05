<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PaymentGatewayServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    PaymentGatewayServiceProvider::class,
];
