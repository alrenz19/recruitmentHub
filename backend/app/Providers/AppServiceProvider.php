<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('verify.recaptcha', \App\Http\Middleware\VerifyRecaptcha::class);
        $this->app->singleton('verify.api', \App\Http\Middleware\VerifyApiRequest::class);
        $this->app->singleton('verify.role', \App\Http\Middleware\RoleMiddleware::class);
        $this->app->singleton('throttle.role_based', \App\Http\Middleware\ThrottleRoleBased::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for older MySQL versions
        Schema::defaultStringLength(191);

        // Optional: globally map the authentication email field
        User::saving(function ($user) {
            if (isset($user->user_email)) {
                $user->email = $user->user_email;
            }
        });

        // Optional: Hash password automatically when creating/updating user
        User::creating(function ($user) {
            if (isset($user->password_hash) && !Hash::needsRehash($user->password_hash)) {
                $user->password_hash = Hash::make($user->password_hash);
            }
        });

        // 🚀 Debug queries in local only
        if ($this->app->environment('local')) {
            DB::listen(function ($query) {
                Log::info("SQL: {$query->sql}", [
                    'bindings' => $query->bindings,
                    'time'     => $query->time
                ]);
            });
        }
    }
}
