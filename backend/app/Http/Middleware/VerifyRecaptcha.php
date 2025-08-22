<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class VerifyRecaptcha
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $request->input('recaptcha_token');

        if (!$token) {
            return response()->json(['message' => 'reCAPTCHA token missing.'], 422);
        }

        // Use cache to avoid repeated verification requests (N+1)
        $cacheKey = $user
            ? "recaptcha:user:{$user->id}"
            : "recaptcha:ip:{$request->ip()}";

        $verified = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($token) {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => env('NOCAPTCHA_SECRET'),
                'response' => $token,
            ])->json();

            return !empty($response['success']) && $response['success'] === true;
        });

        if (!$verified) {
            return response()->json(['message' => 'reCAPTCHA verification failed.'], 422);
        }

        return $next($request);
    }
}
