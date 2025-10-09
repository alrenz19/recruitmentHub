<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\LoginOtp;
use App\Rules\StrongPassword;
use App\Services\SecurityLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use App\Mail\LoginOtpMail;

class AuthController extends Controller
{
    /**
     * Step 1: Verify credentials and send OTP
     */
    public function verifyCredentials(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'user_email' => 'required|email',
            'password'   => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rate limiting for credential verification
        $key = 'credential-verification:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            SecurityLoggerService::log('rate_limit_exceeded', "Too many credential verification attempts", [
                'level' => 'warning',
                'ip' => $request->ip(),
                'email' => $request->user_email
            ]);

            return response()->json([
                'message' => "Too many attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        RateLimiter::hit($key, 300); // 5 minutes

        $user = User::with(['hrStaff', 'applicant'])
            ->where('user_email', $request->user_email)
            ->where('removed', 0)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            SecurityLoggerService::authAttempt('failure', $request->user_email, [
                'reason' => 'Invalid credentials',
                'ip' => $request->ip()
            ]);
            
            RateLimiter::hit($key); // Count failed attempt
            
            return response()->json([
                'message' => 'Invalid email or password'
            ], 401);
        }

        // Generate OTP
        $loginOtp = LoginOtp::generateForEmail($user->user_email);

        // Send OTP via email
        Mail::to($user->user_email)->queue(
            new LoginOtpMail($loginOtp->otp, 10)
        );

        SecurityLoggerService::log('otp_sent', "OTP sent to user email", [
            'level' => 'info',
            'user_id' => $user->id,
            'user_email' => $user->user_email,
            'role_id' => $user->role_id,
            'ip' => $request->ip(),
            'otp_id' => $loginOtp->id
        ]);

        return response()->json([
            'message' => 'If your email is registered, you will receive an OTP shortly.',
            'token' => $loginOtp->token,
            'expires_in' => 600 // 10 minutes in seconds
        ]);
    }

    /**
     * Step 2: Verify OTP and complete login
     */
    public function verifyOtpAndLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'user_email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rate limiting for OTP verification
        $key = 'otp-verification:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            SecurityLoggerService::log('rate_limit_exceeded', "OTP verification rate limit exceeded", [
                'level' => 'warning',
                'email' => $request->user_email,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => "Too many verification attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        RateLimiter::hit($key, 300); // 5 minutes

        // Find valid OTP
        $loginOtp = LoginOtp::findValid($request->token, $request->user_email);

        if (!$loginOtp) {
            SecurityLoggerService::log('otp_verification_failed', "Invalid or expired OTP token", [
                'level' => 'warning',
                'user_email' => $request->user_email,
                'reason' => 'invalid_token',
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'Invalid or expired OTP token.'
            ], 401);
        }

        // Verify OTP code
        if (!hash_equals($loginOtp->otp, $request->otp)) {
            SecurityLoggerService::log('otp_verification_failed', "Invalid OTP code entered", [
                'level' => 'warning',
                'user_email' => $request->user_email,
                'otp_id' => $loginOtp->id,
                'reason' => 'invalid_otp',
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'Invalid OTP code.'
            ], 401);
        }

        // Get user
        $user = User::with(['hrStaff', 'applicant'])
            ->where('user_email', $request->user_email)
            ->where('removed', 0)
            ->first();

        if (!$user) {
            SecurityLoggerService::log('user_not_found', "User not found during OTP verification", [
                'level' => 'error',
                'user_email' => $request->user_email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // Mark OTP as verified
        $loginOtp->markAsVerified();

        // Invalidate all previous tokens and clear cache
        $this->invalidateUserTokens($user);

        // Create new personal access token
        $tokenResult = $user->createToken('auth_token');
        $token = $tokenResult->plainTextToken;
        $tokenDb = $tokenResult->accessToken;

        // Cache token data
        $this->cacheTokenData($user, $token, $tokenDb);
        
        // Log successful login
        SecurityLoggerService::authAttempt('success', $user->user_email, [
            'user_id' => $user->id,
            'role_id' => $user->role_id,
            'ip' => $request->ip(),
            'otp_id' => $loginOtp->id
        ]);

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id'        => $user->id,
                'role_id'   => $user->role_id,
                'full_name' => $user->full_name,
                'user_email' => $user->user_email,
                'position'  => $user->position,
                'accept_privacy_policy' => (bool)$user->accept_privacy_policy
            ],
            'token' => $token
        ]);
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'user_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rate limiting for OTP resend
        $key = 'otp-resend:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many resend attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        RateLimiter::hit($key, 900); // 15 minutes

        // Find existing OTP using encrypted email
        $encryptionService = app(EncryptionService::class);
        $encryptedEmail = $encryptionService->encrypt($request->user_email);

        $existingOtp = LoginOtp::where('token', $request->token)
                            ->where('email', $encryptedEmail)
                            ->first();

        if (!$existingOtp) {
            SecurityLoggerService::log('otp_resend_failed', "Invalid token for OTP resend", [
                'level' => 'warning',
                'user_email' => $request->user_email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'Invalid token.'
            ], 401);
        }

        // Check if we can resend (wait at least 1 minute between resends)
        if ($existingOtp->created_at->diffInSeconds(now()) < 60) {
            SecurityLoggerService::log('otp_resend_throttled', "OTP resend attempted too quickly", [
                'level' => 'warning',
                'user_email' => $request->user_email,
                'otp_id' => $existingOtp->id,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'Please wait before requesting a new OTP.'
            ], 429);
        }

        // Get user
        $user = User::where('user_email', $request->user_email)
                ->where('removed', 0)
                ->first();

        if (!$user) {
            SecurityLoggerService::log('user_not_found', "User not found during OTP resend", [
                'level' => 'error',
                'user_email' => $request->user_email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }

        // Generate new OTP (email is automatically encrypted in generateForEmail)
        $newOtp = LoginOtp::generateForEmail($request->user_email);
        $user->notify(new LoginOtpNotification($newOtp->otp, 10));

        SecurityLoggerService::log('otp_resend_success', "New OTP sent successfully", [
            'level' => 'info',
            'user_id' => $user->id,
            'user_email' => $request->user_email,
            'previous_otp_id' => $existingOtp->id,
            'new_otp_id' => $newOtp->id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'message' => 'New OTP sent to your email address.',
            'token' => $newOtp->token,
            'expires_in' => 600,
        ]);
    }

    /**
     * Check if OTP is still valid
     */
    public function checkOtpStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'user_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $loginOtp = LoginOtp::findValid($request->token, $request->user_email);

        if (!$loginOtp) {
            SecurityLoggerService::log('otp_status_check', "OTP token is invalid or expired", [
                'level' => 'info',
                'user_email' => $request->user_email,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'valid' => false,
                'message' => 'OTP token is invalid or expired'
            ], 404);
        }

        // Calculate seconds remaining properly
        $secondsRemaining = max(0, now()->diffInSeconds($loginOtp->expires_at, false));
        
        // Ensure it's an integer (remove any decimal places)
        $secondsRemaining = (int) $secondsRemaining;

        SecurityLoggerService::log('otp_status_check', "OTP token is still valid", [
            'level' => 'info',
            'user_email' => $request->user_email,
            'otp_id' => $loginOtp->id,
            'seconds_remaining' => $secondsRemaining,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'valid' => true,
            'expires_at' => $loginOtp->expires_at->toISOString(),
            'seconds_remaining' => $secondsRemaining
        ]);
    }

    /**
     * User logout with token cleanup
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'No authenticated user'
            ], 401);
        }

        $token = $user->currentAccessToken();

        if ($token) {
            $this->invalidateSingleToken($user, $token);
            
            // Log logout activity
            SecurityLoggerService::userActivity('logout', [
                'user_id' => $user->id,
                'user_email' => $user->user_email,
                'ip' => $request->ip()
            ]);
        }

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Create new user account
     */
    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_email' => 'required|email|unique:users,user_email',
            'password' => ['required', 'string', 'min:8', new StrongPassword],
            'role_id' => 'required|exists:roles,id',
            'name' => 'required|string|max:255',
            'full_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $userId = DB::table('users')->insertGetId([
                'user_email' => $request->user_email,
                'password_hash' => Hash::make($request->password),
                'role_id' => $request->role_id,
                'name' => $request->name,
                'full_name' => $request->full_name,
                'removed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'email_verified_at' => now(),
            ]);

            $user = DB::table('users')->where('id', $userId)->first();

            DB::commit();

            // Log user creation
            SecurityLoggerService::userActivity('user_created', [
                'user_id' => $userId,
                'user_email' => $user->user_email,
                'created_by' => auth()->id() ?? 'system'
            ]);

            return response()->json([
                'message' => 'User created successfully',
                'user' => [
                    'id' => $user->id,
                    'user_email' => $user->user_email,
                    'name' => $user->name,
                    'full_name' => $user->full_name,
                    'role_id' => $user->role_id,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            SecurityLoggerService::log('user_creation_failed', "Failed to create user account", [
                'level' => 'error',
                'user_email' => $request->user_email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to create user',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Force logout user from all devices (admin function)
     */
    public function forceLogoutUser(Request $request, $userId)
    {
        // Check if current user has admin privileges
        if (!in_array($request->user()->role_id, [1]) && $request->user()->role->name === 'catshatecoffee') { // Adjust role IDs as needed
            SecurityLoggerService::permissionDenied('users', 'force_logout', [
                'user_id' => $request->user()->id,
                'target_user_id' => $userId
            ]);

            return response()->json([
                'message' => 'Unauthorized. Administrator access required.'
            ], 403);
        }

        $user = User::find($userId);
        
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }
    
        $tokensCount = $user->tokens()->count();
        $this->invalidateUserTokens($user);
    
        SecurityLoggerService::userActivity('force_logout', [
            'user_id' => $user->id,
            'user_email' => $user->user_email,
            'tokens_invalidated' => $tokensCount,
            'admin_id' => $request->user()->id
        ]);
    
        return response()->json([
            'message' => 'User logged out from all devices',
            'tokens_invalidated' => $tokensCount
        ]);
    }

    /**
     * Get user's active sessions
     */
    public function getActiveSessions(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'User not authenticated'
            ], 401);
        }
    
        $tokens = $user->tokens()
            ->select('id', 'name', 'last_used_at', 'created_at', 'expires_at')
            ->orderBy('last_used_at', 'desc')
            ->get();
    
        SecurityLoggerService::sensitiveDataAccess('user_sessions', [
            'user_id' => $user->id,
            'sessions_count' => $tokens->count()
        ]);

        return response()->json([
            'sessions' => $tokens,
            'current_session_id' => $user->currentAccessToken()?->id
        ]);
    }

    /**
     * Revoke a specific session
     */
    public function revokeSession(Request $request, $tokenId)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'User not authenticated'
            ], 401);
        }

        $token = $user->tokens()->where('id', $tokenId)->first();
        
        if (!$token) {
            return response()->json([
                'message' => 'Session not found'
            ], 404);
        }

        $this->invalidateSingleToken($user, $token);

        SecurityLoggerService::userActivity('session_revoked', [
            'user_id' => $user->id,
            'user_email' => $user->user_email,
            'token_id' => $tokenId
        ]);

        return response()->json([
            'message' => 'Session revoked successfully'
        ]);
    }

    /**
     * Clean up expired tokens (can be scheduled)
     */
    public function cleanupExpiredTokens(): int
    {
        $expiredTokens = PersonalAccessToken::where('expires_at', '<', now())->get();
        
        $deletedCount = 0;
        foreach ($expiredTokens as $token) {
            Cache::forget("sanctum_token:{$token->token}");
            Cache::forget("sanctum_user:{$token->tokenable_id}");
            $token->delete();
            $deletedCount++;
        }
        
        // Also clean tokens without expires_at that haven't been used in 30 days
        $staleTokens = PersonalAccessToken::whereNull('expires_at')
            ->where('last_used_at', '<', now()->subDays(30))
            ->get();
            
        foreach ($staleTokens as $token) {
            Cache::forget("sanctum_token:{$token->token}");
            Cache::forget("sanctum_user:{$token->tokenable_id}");
            $token->delete();
            $deletedCount++;
        }

        SecurityLoggerService::systemEvent('token_cleanup', [
            'tokens_cleaned' => $deletedCount
        ]);
        
        return $deletedCount;
    }

    /**
     * =========================================================================
     * PRIVATE HELPER METHODS
     * =========================================================================
     */

    /**
     * Efficiently invalidate all user tokens and clear cache
     */
    private function invalidateUserTokens(User $user): void
    {
        // Get token IDs before deletion for cache clearing
        $tokenIds = $user->tokens()->pluck('id')->toArray();
        
        // Delete all tokens in single query
        $user->tokens()->delete();
        
        // Clear cache in bulk
        $cacheKeys = [
            "sanctum_user:{$user->id}",
            "sanctum_latest_token_user:{$user->id}"
        ];
        
        // Add individual token cache keys
        foreach ($tokenIds as $tokenId) {
            $cacheKeys[] = "sanctum_token:{$tokenId}";
        }
        
        Cache::deleteMultiple($cacheKeys);
    }

    /**
     * Invalidate a single token
     */
    private function invalidateSingleToken(User $user, PersonalAccessToken $token): void
    {
        Cache::forget("sanctum_token:{$token->token}");
        Cache::forget("sanctum_user:{$user->id}");
        Cache::forget("sanctum_latest_token_user:{$user->id}");
        
        $token->delete();
    }

    /**
     * Cache token data efficiently
     */
    private function cacheTokenData(User $user, string $token, PersonalAccessToken $tokenDb): void
    {
        $expiration = now()->addMinutes(10);
        
        Cache::put("sanctum_token:{$token}", $tokenDb, $expiration);
        Cache::put("sanctum_user:{$user->id}", $user, $expiration);
        Cache::put("sanctum_latest_token_user:{$user->id}", $tokenDb->token, $expiration);
    }
}