<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for older MySQL versions
        Schema::defaultStringLength(191);

        // Optional: globally map the authentication email field
        // so Laravel recognizes 'user_email' instead of 'email'
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
    }
}
