<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
       
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for older MySQL versions
        Schema::defaultStringLength(191);
        
        // Use our custom token model
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
