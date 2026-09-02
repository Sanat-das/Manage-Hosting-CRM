<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\MailSettingsServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\PaymentGatewayServiceProvider;
use App\Providers\RegistrarServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    FortifyServiceProvider::class,
    MailSettingsServiceProvider::class,
    ModuleServiceProvider::class,
    PaymentGatewayServiceProvider::class,
    RegistrarServiceProvider::class,
];
