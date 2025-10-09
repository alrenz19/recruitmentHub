<?php
// app/Providers/EncryptionServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\EncryptionService;

class EncryptionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}