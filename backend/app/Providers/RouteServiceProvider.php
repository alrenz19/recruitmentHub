<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        // This defines the default "api" rate limiter
        RateLimiter::for('api', function (Request $request) {
            // return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // // Your custom role-based limiter
        // RateLimiter::for('role_based', function (Request $request) {
        //     $user = auth()->user();
        //     if ($user && in_array($user->role_id, [1,2,3])) {
        //         return Limit::perMinute(200)->by($user->id);
        //     }
        //     return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        // });
    }
}
