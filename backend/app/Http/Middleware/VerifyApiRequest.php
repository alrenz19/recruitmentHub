<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class VerifyApiRequest
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // ================================
        // Step 1: Determine cache key safely
        // ================================
        $cacheKey = $user
            ? "api-request:user:{$user->id}"
            : "api-request:ip:{$request->ip()}";

        // ================================
        // Step 2: Rate limiting
        // ================================
        $maxAttempts = 100; // max requests
        $decaySeconds = 10; // per X seconds

        if (RateLimiter::tooManyAttempts($cacheKey, $maxAttempts)) {
            return response()->json(['message' => 'Too many requests'], 429);
        }
        RateLimiter::hit($cacheKey, $decaySeconds);

        // ================================
        // Step 3: Track request count in cache
        // ================================
        $requestCount = Cache::get($cacheKey, 0);
        $requestCount++;
        Cache::put($cacheKey, $requestCount, $decaySeconds);

        // ================================
        // Step 4: Merge info safely into request
        // ================================
        if ($user) {
            $request->merge([
                'auth_user' => $user,
                'api_request_count' => $requestCount,
            ]);
        }

        return $next($request);
    }
}
