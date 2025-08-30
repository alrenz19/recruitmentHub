<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRoleBased
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        $roleId = $user?->role_id ?? 0;

        // Role-based limits (config could be used here)
        $limits = [
            1 => [300, 1],  // CEO
            2 => [120, 1],  // HR Staff
            4 => [60, 1],   // Applicant
            0 => [30, 1],   // Guest
        ];

        [$maxAttempts, $decayMinutes] = $limits[$roleId] ?? [60, 1];

        // Use user ID if authenticated, else IP
        $key = $user 
            ? "throttle:{$user->id}" 
            : "throttle:{$roleId}:{$request->ip()}";

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter
            ], 429)
            ->header('Retry-After', $retryAfter);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        return $next($request);
    }
}
