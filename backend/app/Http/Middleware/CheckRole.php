<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CheckRoleOptimized
{
    /**
     * Handle an incoming request.
     * $role is the required role name (e.g., 'Admin')
     */
    public function handle(Request $request, Closure $next, $role)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Forbidden: not authenticated'], 403);
        }

        // Cache the role name per user to avoid repeated DB queries
        $userRoleName = Cache::remember("user:role:{$user->id}", now()->addMinutes(5), function () use ($user) {
            // If the role relation is defined, eager-load
            return $user->role ? $user->role->name : null;
        });

        if ($userRoleName !== $role) {
            return response()->json(['message' => 'Forbidden: insufficient role'], 403);
        }

        return $next($request);
    }
}
