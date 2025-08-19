<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class VerifyApiRequest
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Ensure request is JSON
        if (!$request->isJson()) {
            return response()->json(['message' => 'Invalid request type'], 400);
        }

        // 2. Rate limiting per IP
        $key = 'api-request:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) { // 10 requests per minute
            return response()->json(['message' => 'Too many requests'], 429);
        }
        RateLimiter::hit($key, 60); // 60 seconds decay

        // 3. Verify reCAPTCHA token
        $token = $request->header('recaptcha-token'); // or $request->input('recaptcha_token')
        if (!$token) {
            return response()->json(['message' => 'Missing reCAPTCHA token'], 403);
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => env('NOCAPTCHA_SECRET'),
            'response' => $token
        ]);

        $body = $response->json();
        if (!($body['success'] ?? false) || ($body['score'] ?? 0) < 0.5) {
            return response()->json(['message' => 'reCAPTCHA verification failed'], 403);
        }

        // 4. Passed all checks
        return $next($request);
    }
}
