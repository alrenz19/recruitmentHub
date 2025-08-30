<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyApiRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // -----------------------------
        // Step 1: Validate API Version Header
        // -----------------------------
        $apiVersion = config('api.version', 'v1');
        if (!$request->hasHeader('X-API-Version') || $request->header('X-API-Version') !== $apiVersion) {
            return response()->json([
                'message' => 'Invalid or missing API version header'
            ], 400);
        }

        // -----------------------------
        // Step 2: Validate Content-Type for non-GET requests
        // -----------------------------
        if (!$request->isMethod('get') && !$request->isMethod('options') && !$request->isJson()) {
            return response()->json([
                'message' => 'Content-Type must be application/json for non-GET requests'
            ], 415);
        }

        // -----------------------------
        // Step 3: Pass request to next middleware / controller
        // -----------------------------
        $response = $next($request);

        // -----------------------------
        // Step 4: Optional: Add security headers
        // -----------------------------
        $response->header('X-API-Version', $apiVersion);
        $response->header('X-Content-Security-Policy', "default-src 'self'");
        $response->header('X-Frame-Options', 'DENY');

        return $response;
    }
}
