<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {

        $user = $request->user();
        $allowedRoles = [1, 2, 3];
        
        // If user is not logged in or doesn't have allowed role
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        // Applicants (role_id = 3) cannot access any admin-only routes
        if (!in_array($user->role_id, $allowedRoles)) {
            return response()->json(['message' => 'This route does not exist'], 403);
        }
        return $next($request);
    }
}
