<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This registers the broadcasting authentication routes
        Broadcast::routes();

        // This loads your channel authorization rules
        require base_path('routes/channels.php');
    }
}