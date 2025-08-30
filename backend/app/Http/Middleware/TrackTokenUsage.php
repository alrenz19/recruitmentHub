<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TrackTokenUsage
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $token = auth()->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $cacheKey = "token_last_used:{$token->id}";

            Cache::remember($cacheKey, now()->addMinutes(5), function () use ($token) {
                $token->update(['last_used_at' => now()]);
                return true;
            });
        }


        return $response;
    }
}
