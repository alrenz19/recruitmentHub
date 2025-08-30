<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     * These middleware are run during every request to your application.
     */
    protected $middleware = [

    ];

    /**
     * The application's route middleware groups.
     */
    protected $middlewareGroups = [
        'web' => [

        ],

        'api' => [

        ],
    ];

    /**
     * The application's route middleware.
     * These middleware may be assigned to groups or used individually.
     */
    protected $routeMiddleware = [

    ];
}