<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PersonalAccessToken;
use App\Rules\StrongPassword;
use App\Services\SecurityLoggerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'user_email' => 'required|email',
            'password'   => 'required|string',
        ]);

        $user = User::with(['hrStaff', 'applicant'])
            ->where('user_email', $request->user_email)
            ->where('removed', 0)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            // Log failed login attempt
            SecurityLoggerService::authAttempt('failure', $request->user_email, [
                'reason' => 'Invalid credentials',
                'ip' => $request->ip()
            ]);
            
            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        //Invalidate all previous tokens (DB + cache)
        $oldTokens = $user->tokens()->where('name', 'auth_token')->get();
        foreach ($oldTokens as $oldToken) {
            Cache::forget("sanctum_token:{$oldToken->token}");
            Cache::forget("sanctum_user:{$oldToken->tokenable_id}");
            Cache::forget("sanctum_latest_token_user:{$oldToken->tokenable_id}");
        }
        $user->tokens()->where('name', 'auth_token')->delete();

        // Create new personal access token
        $tokenResult = $user->createToken('auth_token');
        $token = $tokenResult->plainTextToken;
        $tokenDb = $tokenResult->accessToken;

        // Cache DB token object keyed by plain token
        Cache::put("sanctum_token:{$token}", $tokenDb, now()->addMinutes(10));
        Cache::put("sanctum_user:{$user->id}", $user, now()->addMinutes(10));
        Cache::put("sanctum_latest_token_user:{$user->id}", $tokenDb->token, now()->addMinutes(10)); 
        
        // Log successful login
        SecurityLoggerService::authAttempt('success', $user->user_email, [
            'user_id' => $user->id,
            'role_id' => $user->role_id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id'        => $user->id,
                'role_id'   => $user->role_id,
                'full_name' => $user->full_name,
                'user_email'     => $user->user_email,
                'position'  => $user->position,
                'accept_privacy_policy' => $user->accept_privacy_policy == 0 ? false : true
            ],
            'token' => $token
        ]);
    }


    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        $user = $request->user();

        if ($token) {
            Cache::forget("sanctum_token:{$token->token}");
            Cache::forget("sanctum_user:{$token->tokenable_id}");
            $token->delete();
            
            // Log successful logout
            // if ($user) {
            //     SecurityLoggerService::log('logout', "User logged out successfully", [
            //         'user_id' => $user->id,
            //         'user_email' => $user->user_email,
            //         'ip' => $request->ip()
            //     ]);
            // }
        }

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

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
            'removed' => 0,
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
