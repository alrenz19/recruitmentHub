<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginOtp;
use App\Models\User;
use App\Notifications\LoginOtpNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Logging\SecurityLogger;

class OtpAuthController extends Controller
{
    /**
     * Request OTP for login
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = $request->email;

        // Rate limiting: max 3 OTP requests per 15 minutes per IP
        $key = 'otp-requests:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            
            SecurityLogger::logSuspiciousActivity('OTP rate limit exceeded', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => "Too many OTP requests. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($key, 900); // 15 minutes

        // Check if user exists
        $user = User::where('email', $email)->first();

        if (!$user) {
            // Still return success to prevent email enumeration
            SecurityLogger::logAuthenticationEvent('otp_request', false, [
                'email' => $email,
                'reason' => 'user_not_found',
            ]);

            return response()->json([
                'message' => 'If your email is registered, you will receive an OTP shortly.',
                'token' => 'dummy_token', // Return dummy token for non-existent users
            ]);
        }

        // Generate OTP
        $loginOtp = LoginOtp::generateForEmail($email);

        // Send OTP via email
        $user->notify(new LoginOtpNotification($loginOtp->otp, 10));

        SecurityLogger::logAuthenticationEvent('otp_request', true, [
            'email' => $email,
            'user_id' => $user->id,
            'otp_id' => $loginOtp->id,
        ]);

        return response()->json([
            'message' => 'OTP sent to your email address.',
            'token' => $loginOtp->token,
            'expires_in' => 600, // 10 minutes in seconds
        ]);
    }

    /**
     * Verify OTP and login
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => 'required|string',
        ]);

        $email = $request->email;
        $otp = $request->otp;
        $token = $request->token;
        $password = $request->password;

        // Rate limiting for OTP verification
        $key = 'otp-verification:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            SecurityLogger::logSuspiciousActivity('OTP verification rate limit exceeded', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'otp' => "Too many verification attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($key, 300); // 5 minutes

        // Find valid OTP
        $loginOtp = LoginOtp::findValid($token, $email);

        if (!$loginOtp) {
            SecurityLogger::logAuthenticationEvent('otp_verification', false, [
                'email' => $email,
                'reason' => 'invalid_token',
            ]);

            throw ValidationException::withMessages([
                'otp' => 'Invalid or expired OTP token.',
            ]);
        }

        // Verify OTP code
        if (!hash_equals($loginOtp->otp, $otp)) {
            SecurityLogger::logAuthenticationEvent('otp_verification', false, [
                'email' => $email,
                'otp_id' => $loginOtp->id,
                'reason' => 'invalid_otp',
            ]);

            throw ValidationException::withMessages([
                'otp' => 'Invalid OTP code.',
            ]);
        }

        // Find user and verify password
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            SecurityLogger::logAuthenticationEvent('otp_verification', false, [
                'email' => $email,
                'otp_id' => $loginOtp->id,
                'reason' => 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'password' => 'Invalid credentials.',
            ]);
        }

        // Mark OTP as verified
        $loginOtp->markAsVerified();

        // Create authentication token
        $token = $user->createToken('auth-token')->plainTextToken;

        SecurityLogger::logAuthenticationEvent('otp_verification', true, [
            'email' => $email,
            'user_id' => $user->id,
            'otp_id' => $loginOtp->id,
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->masked_email,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        $email = $request->email;
        $token = $request->token;

        // Find existing OTP
        $existingOtp = LoginOtp::where('token', $token)
                              ->where('email', $email)
                              ->first();

        if (!$existingOtp) {
            throw ValidationException::withMessages([
                'token' => 'Invalid token.',
            ]);
        }

        // Check if we can resend (wait at least 1 minute between resends)
        if ($existingOtp->created_at->diffInSeconds(now()) < 60) {
            throw ValidationException::withMessages([
                'otp' => 'Please wait before requesting a new OTP.',
            ]);
        }

        // Generate new OTP
        $newOtp = LoginOtp::generateForEmail($email);
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->notify(new LoginOtpNotification($newOtp->otp, 10));
        }

        SecurityLogger::logAuthenticationEvent('otp_resend', true, [
            'email' => $email,
            'user_id' => $user?->id,
            'previous_otp_id' => $existingOtp->id,
            'new_otp_id' => $newOtp->id,
        ]);

        return response()->json([
            'message' => 'New OTP sent to your email address.',
            'token' => $newOtp->token,
            'expires_in' => 600,
        ]);
    }
}