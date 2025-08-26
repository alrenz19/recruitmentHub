<?php
// app/Providers/AuthServiceProvider.php

namespace App\Providers;

use App\Models\Assessment;
use App\Policies\AssessmentPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Assessment::class => AssessmentPolicy::class,
        // Add other model-policy mappings here as needed
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // You can add additional authorization logic here if needed
    }
}