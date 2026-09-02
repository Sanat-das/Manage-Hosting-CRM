<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Registrars\RegistrarManager;
use Illuminate\Support\ServiceProvider;

class RegistrarServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RegistrarManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
