<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
        $user = $request->user();
        
        // ================================
        // Step 1: Validate API Version Header
        // ================================
        $apiVersion = config('api.version', 'v1');
        if (!$request->hasHeader('X-API-Version') || 
            $request->header('X-API-Version') !== $apiVersion) {
            return response()->json([
                'message' => 'Invalid or missing API version header'
            ], 400);
        }

        // ================================
        // Step 2: Validate Content-Type for non-GET requests
        // ================================
        if (!$request->isMethod('get') && 
            !$request->isMethod('options') && 
            !$request->isJson()) {
            return response()->json([
                'message' => 'Content-Type must be application/json for non-GET requests'
            ], 415);
        }

        // ================================
        // Step 3: Rate limiting with enhanced security
        // ================================
        $identifier = $user ? 'user:'.$user->id : 'ip:'.$request->ip();
        $cacheKey = 'api-request:'.hash('sha256', $identifier);
        
        // Use config values for rate limits
        $maxAttempts = $user 
            ? config('api.rate_limits.authenticated', 300) 
            : config('api.rate_limits.unauthenticated', 100);
        
        $decaySeconds = 60; // Per minute
        
        // Check if rate limited
        if (RateLimiter::tooManyAttempts($cacheKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($cacheKey);
            
            return response()->json([
                'message' => 'Too many requests',
                'retry_after' => $retryAfter
            ], 429)
            ->header('Retry-After', $retryAfter);
        }
        
        // Increment rate limit
        RateLimiter::hit($cacheKey, $decaySeconds);
        
        // ================================
        // Step 4: Track request fingerprint for anomaly detection (if enabled)
        // ================================
        if (config('api.enable_fingerprinting', true)) {
            $requestFingerprint = $this->generateRequestFingerprint($request);
            $fingerprintKey = 'request-fingerprint:'.hash('sha256', $identifier.':'.$requestFingerprint);
            
            // Track unusual request patterns
            if (Cache::has($fingerprintKey)) {
                // This is a repeated request pattern, potentially suspicious
                Cache::increment($fingerprintKey);
                
                // If same pattern repeated too many times, apply stricter limits
                if (Cache::get($fingerprintKey) > 20) {
                    RateLimiter::hit($cacheKey, 5); // Extra penalty for pattern repetition
                }
            } else {
                Cache::put($fingerprintKey, 1, 300); // Remember for 5 minutes
            }
        }

        // ================================
        // Step 5: Add security headers to response
        // ================================
        $response = $next($request);
        
        // Add rate limit information to headers
        $remainingAttempts = $maxAttempts - RateLimiter::attempts($cacheKey);
        
        return $response
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', $remainingAttempts)
            ->header('X-RateLimit-Reset', time() + RateLimiter::availableIn($cacheKey));
    }
    
    /**
     * Generate a fingerprint for the request to detect patterns
     * 
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function generateRequestFingerprint(Request $request)
    {
        // Create a fingerprint based on method, path, and key parameters
        $fingerprintData = [
            'method' => $request->method(),
            'path' => $request->path(),
            'action' => $request->route() ? $request->route()->getActionName() : 'unknown',
        ];
        
        // For non-GET requests, include a hash of the content
        if (!$request->isMethod('get')) {
            $fingerprintData['content_hash'] = hash('sha256', $request->getContent());
        }
        
        return hash('sha256', json_encode($fingerprintData));
    }
}