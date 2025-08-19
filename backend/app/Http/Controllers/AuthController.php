<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'user_email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Fetch user with Eloquent
        $user = User::where('user_email', $request->user_email)
                    ->where('is_removed', 0)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        // Create a new personal access token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'role_id' => $user->role_id,
            ]
        ])->cookie(
            'auth_token',    // Cookie name
            $token,          // Token value
            60*24,           // Expiration in minutes (1 day)
            '/',             // Path
            null,            // Domain
            true,            // Secure (HTTPS only)
            true             // HttpOnly (JS cannot read)
        );
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            // Delete the current token (used for this request)
            $request->user()->currentAccessToken()->delete();
        }

        // Remove the cookie from the client
        return response()->json([
            'message' => 'Logged out successfully'
        ])->cookie(
            'auth_token', // Cookie name
            null,         // Delete by setting null
            -1,           // Expire immediately
            '/',          
            null,
            true,         // Secure
            true          // HttpOnly
        );
    }


    /**
     * Admin-only: create a new user
     */
    public function createUser(Request $request)
    {
        $request->validate([
            'user_email' => 'required|email|unique:users,user_email',
            'password' => 'required|string|min:6',
            'role_id' => 'required|exists:roles,id',
            'name' => 'required|string|max:255'
        ]);

        $userId = DB::table('users')->insertGetId([
            'user_email' => $request->user_email,
            'password_hash' => Hash::make($request->password),
            'role_id' => $request->role_id,
            'name' => $request->name,
            'is_removed' => 0,
            'created_at' => now(),
            'email_verified_at' => now(),
            'remember_token' => null
        ]);

        $user = DB::table('users')->where('id', $userId)->first();

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user
        ], 201);
    }
}
