<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\PersonalAccessToken;

class AuthCachedMiddleware
{
    public function handle(Request $request, Closure $next){
        $plainToken = $request->bearerToken();

        // Look up DB token object from cache or DB
        $token = Cache::get("sanctum_token:{$plainToken}");
        if (!$token) {
            $token = PersonalAccessToken::findToken($plainToken);
            if (!$token) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
            Cache::put("sanctum_token:{$plainToken}", $token, now()->addMinutes(10));
        }

        $userId = $token->tokenable_id;

        // Get the latest DB token string for this user
        $latestTokenString = Cache::get("sanctum_latest_token_user:{$userId}");
        if (!$latestTokenString) {
            $latestTokenString = PersonalAccessToken::where('tokenable_id', $userId)
                ->where('name', 'auth_token')
                ->latest('created_at')
                ->first()?->token;

            if ($latestTokenString) {
                Cache::put("sanctum_latest_token_user:{$userId}", $latestTokenString, now()->addMinutes(10));
            }
        }

        // Reject if token is not the latest DB token
        if ($token->token !== $latestTokenString) {
            return response()->json(['message' => 'Token expired or invalid'], 401);
        }

        // Cache user for performance
        $user = Cache::remember("sanctum_user:{$userId}", now()->addMinutes(10), fn() => $token->tokenable);
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }

}

